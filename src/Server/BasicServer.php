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
use Icicle\Socket\Exception\{BusyError, ClosedException, FailureException, UnavailableException};
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
     * @var \Icicle\Awaitable\Delayed
     */
    private $delayed;
    
    /**
     * @var \Icicle\Loop\Watcher\Io
     */
    private $poll;
    
    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        parent::__construct($socket);

        $this->poll = $this->createPoll();

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
    protected function free(\Throwable $exception = null)
    {
        $this->poll->free();

        if (null !== $this->delayed) {
            $this->delayed->cancel(
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
        if (null !== $this->delayed) {
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

        $this->poll->listen();
        
        $this->delayed = new Delayed();

        try {
            return yield $this->delayed;
        } catch (\Throwable $exception) {
            $this->poll->cancel();
            throw $exception;
        } finally {
            $this->delayed = null;
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
    public function rebind()
    {
        $pending = $this->poll->isPending();
        $this->poll->free();

        $this->poll = $this->createPoll();

        if ($pending) {
            $this->poll->listen();
        }
    }

    /**
     * @param resource $socket Stream socket resource.
     *
     * @return \Icicle\Socket\Socket
     */
    protected function createSocket($socket): ClientSocket
    {
        return new NetworkSocket($socket);
    }

    /**
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createPoll(): Io
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
                $this->delayed->resolve($this->createSocket($socket));
            } catch (\Exception $exception) {
                $this->delayed->reject($exception);
            }
        });
    }
}
