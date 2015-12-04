<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Datagram;

use Icicle\Awaitable\{Delayed, Exception\TimeoutException};
use Icicle\Exception\InvalidArgumentError;
use Icicle\Loop;
use Icicle\Loop\Watcher\Io;
use Icicle\Socket;
use Icicle\Socket\Exception\{BusyError, ClosedException, FailureException, UnavailableException};
use Icicle\Stream\StreamResource;
use Throwable;

class BasicDatagram extends StreamResource implements Datagram
{
    const MAX_PACKET_SIZE = 512;

    /**
     * @var string
     */
    private $address;
    
    /**
     * @var int
     */
    private $port;
    
    /**
     * @var \Icicle\Awaitable\Deferred|null
     */
    private $delayed;
    
    /**
     * @var \Icicle\Loop\Watcher\Io
     */
    private $poll;
    
    /**
     * @var \Icicle\Loop\Watcher\Io
     */
    private $await;
    
    /**
     * @var \SplQueue
     */
    private $writeQueue;
    
    /**
     * @var int
     */
    private $length = 0;

    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        parent::__construct($socket);
        
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);
        stream_set_chunk_size($socket, self::MAX_PACKET_SIZE);
        
        $this->writeQueue = new \SplQueue();

        $this->poll = $this->createPoll();
        $this->await = $this->createAwait();

        try {
            list($this->address, $this->port) = Socket\getName($socket, false);
        } catch (FailureException $exception) {
            $this->close();
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Frees resources associated with the datagram and closes the datagram.
     *
     * @param \Throwable|null $exception Reason for closing the datagram.
     */
    protected function free(Throwable $exception = null)
    {
        $this->poll->free();
        $this->await->free();

        if (null !== $this->delayed) {
            $this->delayed->resolve('');
        }

        while (!$this->writeQueue->isEmpty()) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            list( , , , $delayed) = $this->writeQueue->shift();
            $delayed->cancel(
                $exception = $exception ?: new ClosedException('The datagram was unexpectedly closed.')
            );
        }

        parent::close();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getAddress(): string
    {
        return $this->address;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getPort(): int
    {
        return $this->port;
    }
    
    /**
     * {@inheritdoc}
     */
    public function receive(int $length = 0, float $timeout = 0): \Generator
    {
        if (null !== $this->delayed) {
            throw new BusyError('Already waiting on datagram.');
        }
        
        if (!$this->isOpen()) {
            throw new UnavailableException('The datagram is no longer readable.');
        }

        $this->length = (int) $length;
        if (0 > $this->length) {
            throw new InvalidArgumentError('The length must be a non-negative integer.');
        } elseif (0 === $this->length) {
            $this->length = self::MAX_PACKET_SIZE;
        }

        $this->poll->listen($timeout);
        
        $this->delayed = new Delayed();

        try {
            return yield $this->delayed;
        } catch (Throwable $exception) {
            $this->poll->cancel();
            throw $exception;
        } finally {
            $this->delayed = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $address, int $port, string $data): \Generator
    {
        if (!$this->isOpen()) {
            throw new UnavailableException('The datagram is no longer writable.');
        }
        
        $data = (string) $data;
        $length = strlen($data);
        $written = 0;
        $peer = Socket\makeName($address, $port);
        
        if ($this->writeQueue->isEmpty()) {
            if (0 === $length) {
                return $written;
            }

            $written = $this->sendTo($this->getResource(), $data, $peer, false);

            if ($length <= $written) {
                return $written;
            }
            
            $data = substr($data, $written);
        }

        $delayed = new Delayed();
        $this->writeQueue->push([$data, $written, $peer, $delayed]);

        if (!$this->await->isPending()) {
            $this->await->listen();
        }

        try {
            return yield $delayed;
        } catch (Throwable $exception) {
            if ($this->isOpen()) {
                $this->free($exception);
            }
            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param float|int $timeout
     */
    public function rebind(float $timeout = 0)
    {
        $pending = $this->poll->isPending();
        $this->poll->free();

        $this->poll = $this->createPoll();

        if ($pending) {
            $this->poll->listen($timeout);
        }

        $pending = $this->await->isPending();
        $this->await->free();

        $this->await = $this->createAwait();

        if ($pending) {
            $this->await->listen();
        }
    }

    /**
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createPoll(): Io
    {
        return Loop\poll($this->getResource(), function ($resource, $expired) {
            try {
                if ($expired) {
                    throw new TimeoutException('The datagram timed out.');
                }

                $data = stream_socket_recvfrom($resource, $this->length, 0, $peer);

                // Having difficulty finding a test to cover this scenario, but the check seems appropriate.
                if (false === $data) { // Reading failed, so close datagram.
                    $message = 'Failed to read from datagram.';
                    if ($error = error_get_last()) {
                        $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
                    }
                    throw new FailureException($message);
                }

                list($address, $port) = Socket\parseName($peer);

                $result = [$address, $port, $data];

                $this->delayed->resolve($result);
            } catch (Throwable $exception) {
                $this->delayed->reject($exception);
            }
        });
    }
    
    /**
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createAwait(): Io
    {
        return Loop\await($this->getResource(), function ($resource) use (&$onWrite) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            list($data, $previous, $peer, $delayed) = $this->writeQueue->shift();

            $length = strlen($data);

            if (0 === $length) {
                $delayed->resolve($previous);
            } else {
                try {
                    $written = $this->sendTo($resource, $data, $peer, true);
                } catch (Throwable $exception) {
                    $delayed->reject($exception);
                    return;
                }

                if ($length <= $written) {
                    $delayed->resolve($written + $previous);
                } else {
                    $data = substr($data, $written);
                    $written += $previous;
                    $this->writeQueue->unshift([$data, $written, $peer, $delayed]);
                }
            }
            
            if (!$this->writeQueue->isEmpty()) {
                $this->await->listen();
            }
        });
    }

    /**
     * @param resource $resource
     * @param string $data
     * @param string $peer
     * @param bool $strict If true, fail if no bytes are written.
     *
     * @return int Number of bytes written.
     *
     * @throws \Icicle\Socket\Exception\FailureException If sending the data fails.
     */
    private function sendTo($resource, string $data, string $peer, bool $strict = false): int
    {
        $written = stream_socket_sendto($resource, substr($data, 0, self::MAX_PACKET_SIZE), 0, $peer);

        // Having difficulty finding a test to cover this scenario, but the check seems appropriate.
        if (false === $written || -1 === $written || (0 === $written && $strict)) {
            $message = 'Failed to write to datagram.';
            if ($error = error_get_last()) {
                $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
            }
            throw new FailureException($message);
        }

        return $written;
    }
}
