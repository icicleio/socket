<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Datagram;

use Exception;
use Icicle\Loop;
use Icicle\Promise\Deferred;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Socket;
use Icicle\Socket\Exception\BusyError;
use Icicle\Socket\Exception\ClosedException;
use Icicle\Socket\Exception\FailureException;
use Icicle\Socket\Exception\UnavailableException;

class Datagram extends Socket\Socket implements DatagramInterface
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
     * @var \Icicle\Promise\Deferred|null
     */
    private $deferred;
    
    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $poll;
    
    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
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
        
        $this->poll = $this->createPoll($socket);
        $this->await = $this->createAwait($socket);
        
        try {
            list($this->address, $this->port) = $this->getName(false);
        } catch (FailureException $exception) {
            $this->free($exception);
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
     * @param \Exception|null $exception Reason for closing the datagram.
     */
    protected function free(Exception $exception = null)
    {
        if (null !== $this->poll) {
            $this->poll->free();
        }

        if (null !== $this->await) {
            $this->await->free();
        }

        if (null !== $this->deferred) {
            $this->deferred->getPromise()->cancel(
                $exception = $exception ?: new ClosedException('The datagram was unexpectedly closed.')
            );
        }

        while (!$this->writeQueue->isEmpty()) {
            /** @var \Icicle\Promise\Deferred $deferred */
            list( , , , $deferred) = $this->writeQueue->shift();
            $deferred->getPromise()->cancel(
                $exception = $exception ?: new ClosedException('The datagram was unexpectedly closed.')
            );
        }

        parent::close();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getAddress()
    {
        return $this->address;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getPort()
    {
        return $this->port;
    }
    
    /**
     * {@inheritdoc}
     */
    public function receive($length = 0, $timeout = 0)
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on datagram.');
        }
        
        if (!$this->isOpen()) {
            throw new UnavailableException('The datagram is no longer readable.');
        }

        $this->length = (int) $length;
        if (0 >= $this->length) {
            $this->length = self::MAX_PACKET_SIZE;
        }

        if (null === $this->poll) {
            $this->poll = $this->createPoll();
        }

        $this->poll->listen($timeout);
        
        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send($address, $port, $data)
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
                yield $written;
                return;
            }

            $written = $this->sendTo($this->getResource(), $data, $peer, false);

            if ($length <= $written) {
                yield $written;
                return;
            }
            
            $data = substr($data, $written);
        }

        $deferred = new Deferred();
        $this->writeQueue->push([$data, $written, $peer, $deferred]);

        if (null === $this->await) {
            $this->await = $this->createAwait();
        }

        if (!$this->await->isPending()) {
            $this->await->listen();
        }

        try {
            yield $deferred->getPromise();
        } catch (Exception $exception) {
            if ($this->isOpen()) {
                $this->free($exception);
            }
            throw $exception;
        }
    }

    /**
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createPoll()
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

                $this->deferred->resolve($result);
            } catch (Exception $exception) {
                $this->deferred->reject($exception);
            }
        });
    }
    
    /**
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createAwait()
    {
        return Loop\await($this->getResource(), function ($resource) use (&$onWrite) {
            /** @var \Icicle\Promise\Deferred $deferred */
            list($data, $previous, $peer, $deferred) = $this->writeQueue->shift();

            $length = strlen($data);

            if (0 === $length) {
                $deferred->resolve($previous);
            } else {
                try {
                    $written = $this->sendTo($resource, $data, $peer, true);
                } catch (Exception $exception) {
                    $deferred->reject($exception);
                    return;
                }

                if ($length <= $written) {
                    $deferred->resolve($written + $previous);
                } else {
                    $data = substr($data, $written);
                    $written += $previous;
                    $this->writeQueue->unshift([$data, $written, $peer, $deferred]);
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
    private function sendTo($resource, $data, $peer, $strict = false)
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
