<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket;

use Exception;
use Icicle\Awaitable;
use Icicle\Awaitable\Promise;
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Socket\Exception\FailureException;
use Icicle\Socket\NetworkSocket;
use Icicle\Socket\Socket;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\BusyError;
use Icicle\Stream\Exception\UnwritableException;

class NetworkSocketTest extends TestCase
{
    const HOST_IPv4 = '127.0.0.1';
    const PORT = 51337;
    const TIMEOUT = 0.1;
    const CONNECT_TIMEOUT = 1;

    public function createSocket()
    {
        $host = self::HOST_IPv4;
        $port = self::PORT;
        
        $context = [];

        $context['socket'] = [
            'connect' => sprintf('%s:%d', $host, $port),
        ];

        $context['ssl'] = [
            'capture_peer_cert' => true,
            'capture_peer_chain' => true,
            'capture_peer_cert_chain' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => true,
            'verify_depth' => 10,
            'CN_match' => 'localhost',
            'SNI_enabled' => true,
            'SNI_server_name' => 'localhost',
            'peer_name' => 'localhost',
            'disable_compression' => true,
        ];

        $context = stream_context_create($context);
        
        $uri = sprintf('tcp://%s:%d', $host, $port);
        $socket = @stream_socket_client(
            $uri,
            $errno,
            $errstr,
            null,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );
        
        if (!$socket || $errno) {
            $this->fail("Could not connect to {$uri}; Errno: {$errno}; {$errstr}");
        }
        
        return new Promise(function ($resolve, $reject) use ($socket) {
            $await = Loop\await($socket, function ($resource, $expired) use (&$await, $resolve, $reject) {
                $await->free();
                $resolve(new NetworkSocket($resource));
            });
            
            $await->listen();
        });
    }
    
    public function createSecureServer($path)
    {
        $host = self::HOST_IPv4;
        $port = self::PORT;

        /** @var callable $generateCert */

        $generateCert = require __DIR__ . '/generate-cert.php';

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
    }
    
    public function testInvalidSocketType()
    {
        $socket = new NetworkSocket(fopen('php://memory', 'r+'));
        
        $this->assertFalse($socket->isOpen());
    }
    
    /**
     * @medium
     * @requires extension openssl
     */
    public function testEnableCrypto()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');
        
        $server = $this->createSecureServer($path);
        
        $promise = $this->createSocket();
        
        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new NetworkSocket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done();
            })
            ->then(function (Socket $socket) {
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            })
            ->tap(function (Socket $socket) {
                $this->assertTrue($socket->isCryptoEnabled());
            });
        
        $promise->done($this->createCallback(1));
        
        Loop\run();
        
        fclose($server);
        unlink($path);
    }

    /**
     * @medium
     * @depends testEnableCrypto
     */
    public function testSimultaneousEnableCrypto()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = $this->createSocket();

        $promise = $promise->then(function (Socket $socket) {
            $promise1 = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            $promise2 = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            return Awaitable\all([$promise1, $promise2]);
        });

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(BusyError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }

    /**
     * @medium
     * @depends testEnableCrypto
     */
    public function testCancelEnableCrypto()
    {
        $exception = new Exception();

        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = $this->createSocket();

        $promise = $promise->then(function (Socket $socket) use ($exception) {
            return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
        });

        Loop\tick(); // Run a few ticks to move into the enable crypto loop.
        Loop\tick();
        Loop\tick();

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }

    /**
     * @medium
     * @depends testEnableCrypto
     */
    public function testEnableCryptoAfterClose()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = $this->createSocket();

        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new NetworkSocket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(0), $this->createCallback(1));
            })
            ->then(function (Socket $socket) {
                $socket->close();
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            });

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }

    /**
     * @medium
     * @depends testEnableCrypto
     */
    public function testEnableCryptoThenClose()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = $this->createSocket();

        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new NetworkSocket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(0), $this->createCallback(1));
            })
            ->then(function (Socket $socket) {
                $promise = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
                Loop\queue([$socket, 'close']);
                return $promise;
            });

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }

    /**
     * @medium
     * @depends testEnableCrypto
     */
    public function testEnableCryptoAfterEnd()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = $this->createSocket();

        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new NetworkSocket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(0), $this->createCallback(1));
            })
            ->then(function (Socket $socket) {
                $coroutine = new Coroutine($socket->end());
                $coroutine->done();
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
            });

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        fclose($server);
        unlink($path);
    }
    
    /**
     * @medium
     * @requires extension openssl
     */
    public function testEnableCryptoFailure()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');
        
        $server = $this->createSecureServer($path);
        
        $promise = $this->createSocket();
        
        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new NetworkSocket($socket);
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(0), $this->createCallback(1));
            })
            ->then(function (Socket $socket) {
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_SSLv3_CLIENT, self::TIMEOUT));
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
     * @requires extension openssl
     */
    public function testEnableCryptoWithNonEmptyStreamBuffer()
    {
        $path = tempnam(sys_get_temp_dir(), 'Icicle');

        $server = $this->createSecureServer($path);

        $promise = $this->createSocket();

        $promise = $promise
            ->tap(function () use ($server) {
                $socket = stream_socket_accept($server);
                $socket = new NetworkSocket($socket);
                $coroutine = new Coroutine($socket->write('Test string'));
                $coroutine->done();
                $coroutine = new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_SERVER, self::TIMEOUT));
                $coroutine->done($this->createCallback(0), $this->createCallback(1));
            })
            ->tap(function (Socket $socket) {
                return $socket->read(0, ' ');
            })
            ->then(function (Socket $socket) {
                return new Coroutine($socket->enableCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT, self::TIMEOUT));
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
