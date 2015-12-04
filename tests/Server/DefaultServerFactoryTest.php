<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Server;

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Socket\Server\DefaultServerFactory;
use Icicle\Socket\Server\Server;
use Icicle\Socket\Socket;
use Icicle\Tests\Socket\TestCase;

class DefaultServerFactoryTest extends TestCase
{
    const HOST_IPv4 = '127.0.0.1';
    const HOST_IPv6 = '[::1]';
    const HOST_UNIX = '/tmp/icicle-tmp.sock';
    const PORT = 51337;
    const TIMEOUT = 0.1;
    const CONNECT_TIMEOUT = 1;
    const CERT_HEADER = '-----BEGIN CERTIFICATE-----';
    
    protected $factory;
    
    protected $server;
    
    public function setUp()
    {
        Loop\loop(new SelectLoop());
        $this->factory = new DefaultServerFactory();
    }
    
    public function tearDown()
    {
        if ($this->server instanceof Server) {
            $this->server->close();
        }
    }
    
    public function testCreate()
    {
        $this->server = $this->factory->create(self::HOST_IPv4, self::PORT);
        
        $this->assertInstanceOf(Server::class, $this->server);
        
        $this->assertSame(self::HOST_IPv4, $this->server->getAddress());
        $this->assertSame(self::PORT, $this->server->getPort());
        
        $this->server->close();
    }
    
    public function testCreateIPv6()
    {
        $this->server = $this->factory->create(self::HOST_IPv6, self::PORT);
        
        $this->assertInstanceOf(Server::class, $this->server);
        
        $this->assertSame(self::HOST_IPv6, $this->server->getAddress());
        $this->assertSame(self::PORT, $this->server->getPort());
    }

    public function testCreateUnix()
    {
        $this->server = $this->factory->create(self::HOST_UNIX, null);

        $this->assertInstanceOf(Server::class, $this->server);

        $this->assertSame(self::HOST_UNIX, $this->server->getAddress());
        $this->assertSame(0, $this->server->getPort());

        unlink(self::HOST_UNIX);
    }
    
    /**
     * @medium
     * @depends testCreate
     * @expectedException \Icicle\Socket\Exception\FailureException
     */
    public function testCreateInvalidHost()
    {
        $this->server = $this->factory->create('invalid.host', self::PORT);
        
        $this->server->close();
    }
    
    /**
     * @medium
     * @requires extension openssl
     * @depends testCreate
     */
    public function testCreateWithPem()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        /** @var callable $generateCert */

        $generateCert = require dirname(__DIR__) . '/generate-cert.php';

        $generateCert(
            'US',
            'MN',
            'Minneapolis',
            'Icicle',
            'Security',
            'localhost',
            'hello@icicle.io',
            null,
            $path
        );

        $this->server = $this->factory->create(self::HOST_IPv4, self::PORT, ['pem' => $path]);
        
        $this->assertInstanceOf(Server::class, $this->server);
        
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
                 ->with($this->isInstanceOf(Socket::class));
        
        $promise->done($callback);
        
        Loop\run();
        
        unlink($path);
    }
    
    /**
     * @expectedException \Icicle\Exception\InvalidArgumentError
     */
    public function testCreateWithInvalidPemPath()
    {
        $this->server = $this->factory->create(self::HOST_IPv4, self::PORT, ['pem' => 'invalid/pem.pem']);
    }
}
