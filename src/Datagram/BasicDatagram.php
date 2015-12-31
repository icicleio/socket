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
use Icicle\Socket\Exception\{ClosedException, FailureException, UnavailableException};
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
    private $readQueue;
    
    /**
     * @var \SplQueue
     */
    private $writeQueue;

    /**
     * @var \Closure
     */
    private $onReceiveCancelled;

    /**
     * @var \Closure
     */
    private $onSendCancelled;
    
    /**
     * @var int
     */
    private $length = 0;

    /**
     * @param resource $socket
     * @param bool $autoClose True to close the resource on destruct, false to leave it open.
     */
    public function __construct($socket, bool $autoClose = true)
    {
        parent::__construct($socket, $autoClose);
        
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);
        stream_set_chunk_size($socket, self::MAX_PACKET_SIZE);

        $this->readQueue = new \SplQueue();
        $this->writeQueue = new \SplQueue();

        $this->poll = $this->createPoll($socket, $this->readQueue);
        $this->await = $this->createAwait($socket, $this->writeQueue);

        $this->onReceiveCancelled = function () {
            $this->poll->cancel();
            $this->readQueue->shift();
        };

        $this->onSendCancelled = function (\Exception $exception) {
            $this->free($exception);
        };

        try {
            list($this->address, $this->port) = Socket\getName($socket, false);
        } catch (FailureException $exception) {
            $this->close();
        }
    }

    /**
     * Frees resources associated with this object from the loop.
     */
    public function __destruct()
    {
        parent::__destruct();
        $this->free();
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        parent::close();
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

        if (null !== $this->await) {
            $this->await->free();
        }

        while (!$this->readQueue->isEmpty()) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            $delayed = $this->readQueue->shift();
            $delayed->resolve();
        }

        while (!$this->writeQueue->isEmpty()) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            list( , , , $delayed) = $this->writeQueue->shift();
            $delayed->reject(
                $exception = $exception ?: new ClosedException('The datagram was unexpectedly closed.')
            );
        }
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
        while (!$this->readQueue->isEmpty()) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            $delayed = $this->readQueue->bottom();
            yield $delayed; // Wait for previous read to complete.
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

        $this->readQueue->push($delayed = new Delayed($this->onReceiveCancelled));
        $this->poll->listen($timeout);

        return yield $delayed;
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

            $written = stream_socket_sendto($this->getResource(), substr($data, 0, self::MAX_PACKET_SIZE), 0, $peer);

            // Having difficulty finding a test to cover this scenario, but the check seems appropriate.
            if (false === $written || -1 === $written) {
                $message = 'Failed to write to datagram.';
                if ($error = error_get_last()) {
                    $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
                }
                throw new FailureException($message);
            }

            if ($length <= $written) {
                return $written;
            }
            
            $data = substr($data, $written);
        }

        if (null === $this->await) {
            $this->await = $this->createAwait($this->getResource(), $this->writeQueue);
            $this->await->listen();
        } elseif (!$this->await->isPending()) {
            $this->await->listen();
        }

        $delayed = new Delayed($this->onSendCancelled);
        $this->writeQueue->push([$data, $written, $peer, $delayed]);

        try {
            return yield $delayed;
        } catch (Throwable $exception) {
            $this->free($exception);
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

        $this->poll = $this->createPoll($this->getResource(), $this->readQueue);

        if ($pending) {
            $this->poll->listen($timeout);
        }

        if (null !== $this->await) {
            $pending = $this->await->isPending();
            $this->await->free();

            $this->await = $this->createAwait($this->getResource(), $this->writeQueue);

            if ($pending) {
                $this->await->listen();
            }
        }
    }

    /**
     * @param resource $resource
     * @param \SplQueue $readQueue
     *
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createPoll($resource, \SplQueue $readQueue): Io
    {
        $length = &$this->length;

        return Loop\poll($resource, static function ($resource, bool $expired) use (&$length, $readQueue) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            $delayed = $readQueue->shift();

            try {
                if ($expired) {
                    throw new TimeoutException('The datagram timed out.');
                }

                $data = stream_socket_recvfrom($resource, $length, 0, $peer);

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

                $delayed->resolve($result);
            } catch (Throwable $exception) {
                $delayed->reject($exception);
            }
        });
    }
    
    /**
     * @param resource $resource
     * @param \SplQueue $writeQueue
     *
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createAwait($resource, \SplQueue $writeQueue): Io
    {
        return Loop\await($resource, static function ($resource, bool $expired, Io $await) use ($writeQueue) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            list($data, $previous, $peer, $delayed) = $writeQueue->shift();

            $length = strlen($data);

            if (0 === $length) {
                $delayed->resolve($previous);
            } else {
                $written = stream_socket_sendto($resource, substr($data, 0, self::MAX_PACKET_SIZE), 0, $peer);

                // Having difficulty finding a test to cover this scenario, but the check seems appropriate.
                if (false === $written || -1 === $written || 0 === $written) {
                    $message = 'Failed to write to datagram.';
                    if ($error = error_get_last()) {
                        $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
                    }
                    $delayed->reject(new FailureException($message));
                    return;
                }

                if ($length <= $written) {
                    $delayed->resolve($written + $previous);
                } else {
                    $data = substr($data, $written);
                    $written += $previous;
                    $writeQueue->unshift([$data, $written, $peer, $delayed]);
                }
            }
            
            if (!$writeQueue->isEmpty()) {
                $await->listen();
            }
        });
    }
}
