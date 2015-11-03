<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Server;

use Exception;
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\LoopInterface;
use Icicle\Loop\SelectLoop;
use Icicle\Socket\Exception\BusyError;
use Icicle\Socket\Exception\ClosedException;
use Icicle\Socket\Exception\UnavailableException;
use Icicle\Socket\Server\Server;
use Icicle\Socket\SocketInterface;
use Icicle\Tests\Socket\TestCase;

class ServerTest extends TestCase
{
    const HOST_IPv4 = '127.0.0.1';
    const PORT = 51337;
    const TIMEOUT = 0.1;
    const CONNECT_TIMEOUT = 1;

    /**
     * @var \Icicle\Socket\Server\Server|null
     */
    protected $server;

    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }

    public function tearDown()
    {
        if ($this->server instanceof Server) {
            $this->server->close();
        }
    }
    
    public function createServer()
    {
        $host = self::HOST_IPv4;
        $port = self::PORT;
        
        $context = [];
        
        $context['socket'] = [];
        $context['socket']['bindto'] = "{$host}:{$port}";
        
        $context = stream_context_create($context);
        
        $uri = sprintf('tcp://%s:%d', $host, $port);
        $socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        
        if (!$socket || $errno) {
            $this->fail("Could not create server {$host}:{$port}: [Errno: {$errno}] {$errstr}");
        }
        
        return new Server($socket);
    }
    
    public function testInvalidSocketType()
    {
        $this->server = new Server(fopen('php://memory', 'r+'));
        
        $this->assertFalse($this->server->isOpen());
    }
    
    public function testAccept()
    {
        $this->server = $this->createServer();
        
        $promise = new Coroutine($this->server->accept());
        
        $client = stream_socket_client(
            'tcp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(SocketInterface::class));
        
        $promise->done($callback);
        
        Loop\run();

        fclose($client);
    }

    /**
     * @depends testAccept
     */
    public function testAcceptWithPendingConnection()
    {
        $this->server = $this->createServer();

        $client = stream_socket_client(
            'tcp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );

        $promise = new Coroutine($this->server->accept());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        Loop\run();

        fclose($client);
    }

    /**
     * @depends testAccept
     */
    public function testAcceptAfterClose()
    {
        $this->server = $this->createServer();
        
        $this->server->close();
        
        $promise = new Coroutine($this->server->accept());
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(UnavailableException::class));
        
        $promise->done($this->createCallback(0), $callback);
        
        Loop\run();
    }
    
    /**
     * @depends testAccept
     */
    public function testAcceptThenClose()
    {
        $this->server = $this->createServer();
        
        $promise = new Coroutine($this->server->accept());
        
        $this->server->close();
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(ClosedException::class));
        
        $promise->done($this->createCallback(0), $callback);
        
        Loop\run();
    }
    
    /**
     * @depends testAccept
     */
    public function testCancelAccept()
    {
        $exception = new Exception();
        
        $this->server = $this->createServer();
        
        $promise = new Coroutine($this->server->accept());
        
        $promise->cancel($exception);
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->identicalTo($exception));
        
        $promise->done($this->createCallback(0), $callback);
        
        Loop\run();
    }

    /**
     * @depends testAccept
     */
    public function testSimultaneousAccept()
    {
        $this->server = $this->createServer();
        
        $promise1 = new Coroutine($this->server->accept());
        
        $promise2 = new Coroutine($this->server->accept());
        
        $client = stream_socket_client(
            'tcp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(SocketInterface::class));
        
        $promise1->done($callback);
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(BusyError::class));
        
        $promise2->done($this->createCallback(0), $callback);
        
        Loop\run();

        fclose($client);
    }
    
    /**
     * @depends testAccept
     */
    public function testAcceptOnClosedClient()
    {
        $this->server = $this->createServer();
        
        $promise = new Coroutine($this->server->accept());
        
        $client = stream_socket_client(
            'tcp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );
        
        if (!$client || $errno) {
            $this->fail("Could not create client socket. [Errno {$errno}] {$errstr}");
        }

        fclose($client);
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(SocketInterface::class));
        
        $promise->done($callback);
        
        Loop\run();
    }

    public function testRebind()
    {
        $this->server = $this->createServer();

        $loop = $this->getMock(LoopInterface::class);

        $loop->expects($this->once())
            ->method('poll')
            ->will($this->returnValue($this->getMock(Loop\Events\SocketEventInterface::class)));

        Loop\loop($loop);

        $this->server->rebind();
    }

    /**
     * @depends testRebind
     * @expectedException \Icicle\Socket\Exception\BusyError
     */
    public function testRebindWhileBusy()
    {
        $this->server = $this->createServer();

        $promise = new Coroutine($this->server->accept());

        $this->server->rebind();
    }
}
