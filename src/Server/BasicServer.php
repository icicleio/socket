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
use Icicle\Socket;
use Icicle\Socket\NetworkSocket;
use Icicle\Socket\Exception\BusyError;
use Icicle\Socket\Exception\ClosedException;
use Icicle\Socket\Exception\FailureException;
use Icicle\Socket\Exception\UnavailableException;
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
     * @param \Exception $exception Reason for closing the server.
     */
    protected function free(\Exception $exception = null)
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
    public function accept()
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
            yield $this->createSocket($socket);
            return;
        }

        $this->poll->listen();
        
        $this->delayed = new Delayed();

        try {
            yield $this->delayed;
        } catch (\Exception $exception) {
            $this->poll->cancel();
            throw $exception;
        } finally {
            $this->delayed = null;
        }
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
    protected function createSocket($socket)
    {
        return new NetworkSocket($socket);
    }

    /**
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createPoll()
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
