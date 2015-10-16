<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Connector;

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Socket\Connector\Connector;
use Icicle\Socket\Exception\FailureException;
use Icicle\Socket\Exception\InvalidArgumentError;
use Icicle\Socket\Socket;
use Icicle\Socket\SocketInterface;
use Icicle\Tests\Socket\TestCase;

class ConnectorTest extends TestCase
{
    const HOST_IPv4 = '127.0.0.1';
    const HOST_IPv6 = '[::1]';
    const HOST_UNIX = '/tmp/icicle-tmp.sock';
    const PORT = 51337;
    const TIMEOUT = 1;

    /**
     * @var \Icicle\Socket\Connector\ConnectorInterface
     */
    protected $connector;
    
    public function createServer()
    {
        $host = self::HOST_IPv4;
        $port = self::PORT;
        
        $context = [];
        
        $context['socket'] = [];
        $context['socket']['bindto'] = "{$host}:{$port}";
        
        $context = stream_context_create($context);
        
        $socket = stream_socket_server(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        
        if (!$socket || $errno) {
            $this->fail(sprintf('Could not create server %s:%d: [Errno: %d] %s', $host, $port, $errno, $errstr));
        }
        
        return $socket;
    }
    
    public function createServerIPv6()
    {
        $host = self::HOST_IPv6;
        $port = self::PORT;
        
        $context = [];
        
        $context['socket'] = [];
        $context['socket']['bindto'] = "{$host}:{$port}";
        
        $context = stream_context_create($context);
        
        $socket = stream_socket_server(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        
        if (!$socket || $errno) {
            $this->fail(sprintf('Could not create server %s:%d: [Errno: %d] %s', $host, $port, $errno, $errstr));
        }
        
        return $socket;
    }

    public function createServerUnix($path)
    {
        $context = [];

        $context['socket'] = [];
        $context['socket']['bindto'] = $path;

        $context = stream_context_create($context);

        $socket = stream_socket_server(
            sprintf('unix://%s', $path),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$socket || $errno) {
            $this->fail(sprintf('Could not create server %s: [Errno: %d] %s', $path, $errno, $errstr));
        }

        return $socket;
    }

    public function createSecureServer($path)
    {
        $host = self::HOST_IPv4;
        $port = self::PORT;

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

        $context = [];

        $context['socket'] = [];
        $context['socket']['bindto'] = "{$host}:{$port}";

        $context['ssl'] = [];
        $context['ssl']['local_cert'] = $path;
        $context['ssl']['disable_compression'] = true;

        $context = stream_context_create($context);

        $socket = stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$socket || $errno) {
            $this->fail("Could not create server {$host}:{$port}: [Errno: {$errno}] {$errstr}");
        }

        return $socket;
    }
    
    public function setUp()
    {
        Loop\loop(new SelectLoop());
        $this->connector = new Connector();
    }

    public function testConnect()
    {
        $server = $this->createServer();
        
        $promise = new Coroutine($this->connector->connect(self::HOST_IPv4, self::PORT));
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(SocketInterface::class));
        
        $promise->done($callback);
        
        $promise->done(function (Socket $socket) {
            $this->assertTrue($socket->isOpen());
            $this->assertSame($socket->getLocalAddress(), self::HOST_IPv4);
            $this->assertSame($socket->getRemoteAddress(), self::HOST_IPv4);
            $this->assertInternalType('integer', $socket->getLocalPort());
            $this->assertSame($socket->getRemotePort(), self::PORT);
        });
        
        Loop\run();
        
        fclose($server);
    }
    
    /**
     * @depends testConnect
     */
    public function testConnectIPv6()
    {
        $server = $this->createServerIPv6();
        
        $promise = new Coroutine($this->connector->connect(self::HOST_IPv6, self::PORT));
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(SocketInterface::class));
        
        $promise->done($callback);
        
        $promise->done(function (Socket $socket) {
            $this->assertTrue($socket->isOpen());
            $this->assertSame($socket->getLocalAddress(), self::HOST_IPv6);
            $this->assertSame($socket->getRemoteAddress(), self::HOST_IPv6);
            $this->assertInternalType('integer', $socket->getLocalPort());
            $this->assertSame($socket->getRemotePort(), self::PORT);
        });
        
        Loop\run();
        
        fclose($server);
    }

    /**
     * @depends testConnect
     */
    public function testConnectUnix()
    {
        $server = $this->createServerUnix(self::HOST_UNIX);

        $promise = new Coroutine($this->connector->connect(self::HOST_UNIX, null));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        $promise->done(function (Socket $socket) {
            $this->assertTrue($socket->isOpen());
            $this->assertSame(self::HOST_UNIX, $socket->getRemoteAddress());
            $this->assertSame(0, $socket->getRemotePort());
        });

        Loop\run();

        fclose($server);
        unlink(self::HOST_UNIX);
    }
    
    /**
     * @medium
     * @depends testConnect
     */
    public function testConnectFailure()
    {
        $promise = new Coroutine($this->connector->connect('invalid.host', self::PORT, ['timeout' => 1]));
        
        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(FailureException::class));
        
        $promise->done($this->createCallback(0), $callback);
        
        Loop\run();
    }
    
    /**
     * @medium
     * @depends testConnect
     */
    public function testConnectTimeout()
    {
        $promise = new Coroutine($this->connector->connect('8.8.8.8', 8080, ['timeout' => 1]));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @medium
     * @depends testConnect
     */
    public function testConnectWithCAFile()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createServer();

        $promise = new Coroutine($this->connector->connect(self::HOST_IPv4, self::PORT, ['cafile' => $path]));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        Loop\run();

        fclose($server);

        unlink($path);
    }

    public function testInvalidCAFile()
    {
        $path = '/invalid/path/to/cafile.pem';

        $server = $this->createServer();

        $promise = new Coroutine($this->connector->connect(self::HOST_IPv4, self::PORT, ['cafile' => $path]));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
                 ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
    }

    /**
     * @medium
     * @requires extension openssl
     * @depends testConnect
     */
    public function testSecureConnect()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = new Coroutine($this->connector->connect(
            self::HOST_IPv4,
            self::PORT,
            ['name' => 'localhost', 'cn' => 'localhost', 'allow_self_signed' => true]
        ));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new Socket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(1));
            })
            ->then(function (Socket $socket) {
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            })
            ->tap(function (Socket $socket) {
                $this->assertTrue($socket->isCryptoEnabled());
            });

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }

    /**
     * @medium
     * @depends testSecureConnect
     */
    public function testSecureConnectNameMismatch()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = new Coroutine($this->connector->connect(
            self::HOST_IPv4,
            self::PORT,
            ['name' => 'icicle.io', 'cn' => 'icicle.io', 'allow_self_signed' => true]
        ));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new Socket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(1));
            })
            ->then(function (Socket $socket) {
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            })
            ->tap(function (Socket $socket) {
                $this->assertTrue($socket->isCryptoEnabled());
            });

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(FailureException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }

    /**
     * @medium
     * @depends testSecureConnect
     */
    public function testSecureConnectNoSelfSigned()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = new Coroutine($this->connector->connect(
            self::HOST_IPv4,
            self::PORT,
            ['name' => 'localhost', 'cn' => 'localhost', 'allow_self_signed' => false]
        ));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new Socket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(0), $this->createCallback(1));
            })
            ->then(function (Socket $socket) {
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            })
            ->tap(function (Socket $socket) {
                $this->assertTrue($socket->isCryptoEnabled());
            });

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(FailureException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }
}
