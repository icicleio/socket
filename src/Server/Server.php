<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Server;

use Icicle\Loop;
use Icicle\Loop\Events\SocketEventInterface;
use Icicle\Promise\Deferred;
use Icicle\Socket\Client\{Client, ClientInterface};
use Icicle\Socket\Exception\{BusyError, ClosedException, FailureException, UnavailableException};
use Icicle\Socket\Socket;
use Throwable;

class Server extends Socket implements ServerInterface
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
        
        $this->poll = $this->createPoll($socket);
        
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
     * Frees resources associated with the server and closes the server.
     *
     * @param \Throwable $exception Reason for closing the server.
     */
    protected function free(Throwable $exception = null)
    {
        $this->poll->free();

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
     * @return \Icicle\Socket\Client\ClientInterface
     */
    protected function createClient($socket): ClientInterface
    {
        return new Client($socket);
    }

    /**
     * @param resource $socket
     *
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createPoll($socket): SocketEventInterface
    {
        return Loop\poll($socket, function ($resource) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            $client = @stream_socket_accept($resource, 0); // Timeout of 0 to be non-blocking.

            // Having difficulty finding a test to cover this scenario, but it has been seen in production.
            if (!$client) {
                $this->poll->listen(); // Accept failed, let's go around again.
                return;
            }

            try {
                $this->deferred->resolve($this->createClient($client));
            } catch (Throwable $exception) {
                $this->deferred->reject($exception);
            }
        });
    }
}
