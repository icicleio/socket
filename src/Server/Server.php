<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Server;

use Icicle\Loop;
use Icicle\Loop\Events\SocketEventInterface;
use Icicle\Promise\Deferred;
use Icicle\Socket;
use Icicle\Socket\Exception\{BusyError, ClosedException, FailureException, UnavailableException};
use Icicle\Socket\{Socket as ClientSocket, SocketInterface};
use Icicle\Stream\StreamResource;
use Throwable;

class Server extends StreamResource implements ServerInterface
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
     * @var \Icicle\Promise\Deferred
     */
    private $deferred;
    
    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $poll;
    
    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        parent::__construct($socket);
        
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
     * Frees resources associated with the server and closes the server.
     *
     * @param \Throwable $exception Reason for closing the server.
     */
    protected function free(Throwable $exception = null)
    {
        if (null !== $this->poll) {
            $this->poll->free();
        }

        if (null !== $this->deferred) {
            $this->deferred->getPromise()->cancel(
                $exception ?: new ClosedException('The server was unexpectedly closed.')
            );
        }

        parent::close();
    }
    
    /**
     * {@inheritdoc}
     */
    public function accept(): \Generator
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on server.');
        }
        
        if (!$this->isOpen()) {
            throw new UnavailableException('The server has been closed.');
        }

        // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
        $socket = @stream_socket_accept($this->getResource(), 0); // Timeout of 0 to be non-blocking.

        if ($socket) {
            return $this->createSocket($socket);
        }

        if (null === $this->poll) {
            $this->poll = $this->createPoll();
        }

        $this->poll->listen();
        
        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            return yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
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
     * @param resource $socket Stream socket resource.
     *
     * @return \Icicle\Socket\SocketInterface
     */
    protected function createSocket($socket): SocketInterface
    {
        return new ClientSocket($socket);
    }

    /**
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createPoll(): SocketEventInterface
    {
        return Loop\poll($this->getResource(), function ($resource) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            $socket = @stream_socket_accept($resource, 0); // Timeout of 0 to be non-blocking.

            // Having difficulty finding a test to cover this scenario, but it has been seen in production.
            if (!$socket) {
                $this->poll->listen(); // Accept failed, let's go around again.
                return;
            }

            try {
                $this->deferred->resolve($this->createSocket($socket));
            } catch (Throwable $exception) {
                $this->deferred->reject($exception);
            }
        });
    }
}
