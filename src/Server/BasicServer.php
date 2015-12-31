<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Server;

use Icicle\Awaitable\Delayed;
use Icicle\Loop;
use Icicle\Loop\Watcher\Io;
use Icicle\Socket;
use Icicle\Socket\{Socket as ClientSocket, NetworkSocket};
use Icicle\Socket\Exception\{ClosedException, FailureException, UnavailableException};
use Icicle\Stream\StreamResource;

class BasicServer extends StreamResource implements Server
{
    /**
     * Listening hostname or IP address.
     *
     * @var int
     */
    private $address;
    
    /**
     * Listening port.
     *
     * @var int
     */
    private $port;
    
    /**
     * @var \SplQueue
     */
    private $queue;
    
    /**
     * @var \Icicle\Loop\Watcher\Io
     */
    private $poll;

    /**
     * @var \Closure
     */
    private $onCancelled;
    
    /**
     * @param resource $socket
     * @param bool $autoClose True to close the resource on destruct, false to leave it open.
     */
    public function __construct($socket, bool $autoClose = true)
    {
        parent::__construct($socket, $autoClose);

        $this->queue = new \SplQueue();

        $this->poll = $this->createPoll($socket, $this->queue);

        $this->onCancelled = function () {
            $this->poll->cancel();
            $this->queue->shift();
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
     * Frees resources associated with the server and closes the server.
     *
     * @param \Throwable $exception Reason for closing the server.
     */
    protected function free(\Throwable $exception = null)
    {
        $this->poll->free();

        while (!$this->queue->isEmpty()) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            $delayed = $this->queue->shift();
            $delayed->reject($exception ?: new ClosedException('The server was unexpectedly closed.'));
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function accept(bool $autoClose = true): \Generator
    {
        while (!$this->queue->isEmpty()) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            $delayed = $this->queue->bottom();
            yield $delayed; // Wait for previous accept to complete.
        }
        
        if (!$this->isOpen()) {
            throw new UnavailableException('The server has been closed.');
        }

        // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
        $socket = @stream_socket_accept($this->getResource(), 0); // Timeout of 0 to be non-blocking.

        if ($socket) {
            return $this->createSocket($socket, $autoClose);
        }

        $this->queue->push($delayed = new Delayed($this->onCancelled));
        $this->poll->listen();

        return $this->createSocket(yield $delayed, $autoClose);
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
    public function rebind()
    {
        $pending = $this->poll->isPending();
        $this->poll->free();

        $this->poll = $this->createPoll($this->getResource(), $this->queue);

        if ($pending) {
            $this->poll->listen();
        }
    }

    /**
     * @param resource $socket Stream socket resource.
     * @param bool $autoClose
     *
     * @return \Icicle\Socket\Socket
     */
    protected function createSocket($socket, bool $autoClose = true): ClientSocket
    {
        return new NetworkSocket($socket, $autoClose);
    }

    /**
     * @param resource $resource
     * @param \SplQueue $queue
     *
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createPoll($resource, \SplQueue $queue): Io
    {
        return Loop\poll($resource, static function ($resource, bool $expired, Io $poll) use ($queue) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            $socket = @stream_socket_accept($resource, 0); // Timeout of 0 to be non-blocking.

            // Having difficulty finding a test to cover this scenario, but it has been seen in production.
            if (!$socket) {
                $poll->listen(); // Accept failed, let's go around again.
                return;
            }

            /** @var \Icicle\Awaitable\Delayed $delayed */
            $delayed = $queue->shift();
            $delayed->resolve($socket);
        });
    }
}
