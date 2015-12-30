<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Datagram;

use Exception;
use Icicle\Awaitable\Exception\TimeoutException;
use Icicle\Coroutine\Coroutine;
use Icicle\Exception\InvalidArgumentError;
use Icicle\Loop;
use Icicle\Loop\Loop as LoopInterface;
use Icicle\Loop\SelectLoop;
use Icicle\Loop\Watcher\Io;
use Icicle\Socket\Datagram\BasicDatagram;
use Icicle\Socket\Datagram\Datagram;
use Icicle\Socket\Exception\ClosedException;
use Icicle\Socket\Exception\UnavailableException;
use Icicle\Tests\Socket\TestCase;

class BasicDatagramTest extends TestCase
{
    const HOST_IPv4 = '127.0.0.1';
    const HOST_IPv6 = '[::1]';
    const PORT = 51337;
    const CONNECT_TIMEOUT = 1;

    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';

    protected $datagram;

    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }

    public function tearDown()
    {
        if ($this->datagram instanceof Datagram) {
            $this->datagram->close();
        }
    }

    public function createDatagram()
    {
        $host = self::HOST_IPv4;
        $port = self::PORT;

        $context = [];

        $context['socket'] = [];
        $context['socket']['bindto'] = "{$host}:{$port}";

        $context = stream_context_create($context);

        $uri = sprintf('udp://%s:%d', $host, $port);
        $socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context);

        if (!$socket || $errno) {
            $this->fail("Could not create datagram on {$host}:{$port}: [Errno: {$errno}] {$errstr}");
        }

        return new BasicDatagram($socket);
    }

    public function createDatagramIPv6()
    {
        $host = self::HOST_IPv6;
        $port = self::PORT;

        $context = [];

        $context['socket'] = [];
        $context['socket']['bindto'] = "{$host}:{$port}";

        $context = stream_context_create($context);

        $uri = sprintf('udp://%s:%d', $host, $port);
        $socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context);

        if (!$socket || $errno) {
            $this->fail("Could not create datagram on {$host}:{$port}: [Errno: {$errno}] {$errstr}");
        }

        return new BasicDatagram($socket);
    }

    public function testInvalidSocketType()
    {
        $this->datagram = new BasicDatagram(fopen('php://memory', 'r+'));

        $this->assertFalse($this->datagram->isOpen());
    }

    public function testReceive()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
            $this->fail('Could not write to datagram.');
        }

        $promise = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->will($this->returnCallback(function ($data) {
                list($address, $port, $message) = $data;
                $this->assertSame(self::HOST_IPv4, $address);
                $this->assertInternalType('integer', $port);
                $this->assertGreaterThan(0, $port);
                $this->assertSame(self::WRITE_STRING, $message);
            }));

        $promise->done($callback);

        Loop\run();
    }

    public function testReceiveFromIPv6()
    {
        $this->datagram = $this->createDatagramIPv6();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv6 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
            $this->fail('Could not write to datagram.');
        }

        $promise = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->will($this->returnCallback(function ($data) {
                list($address, $port, $message) = $data;
                $this->assertSame(self::HOST_IPv6, $address);
                $this->assertInternalType('integer', $port);
                $this->assertGreaterThan(0, $port);
                $this->assertSame(self::WRITE_STRING, $message);
            }));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReceive
     */
    public function testReceiveAfterClose()
    {
        $this->datagram = $this->createDatagram();

        $this->datagram->close();

        $promise = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnavailableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReceive
     */
    public function testReceiveThenClose()
    {
        $this->datagram = $this->createDatagram();

        $promise = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(null));

        $promise->done($callback);

        $this->datagram->close();

        Loop\run();
    }

    /**
     * @depends testReceive
     */
    public function testSimultaneousReceive()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
            $this->fail('Could not write to datagram.');
        }

        $promise1 = new Coroutine($this->datagram->receive());

        $promise2 = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(2);
        $callback->method('__invoke')
            ->will($this->returnCallback(function ($data) {
                list($address, $port, $message) = $data;
                $this->assertSame(self::HOST_IPv4, $address);
                $this->assertInternalType('integer', $port);
                $this->assertGreaterThan(0, $port);
                $this->assertSame(self::WRITE_STRING, $message);
            }));

        $promise1->done($callback);
        $promise2->done($callback);

        Loop\timer(self::TIMEOUT, function () use ($client) {
            if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
                $this->fail('Could not write to datagram.');
            }
        });

        Loop\run();
    }

    /**
     * @depends testReceive
     */
    public function testReceiveWithLength()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
            $this->fail('Could not write to datagram.');
        }

        $length = (int) floor(strlen(self::WRITE_STRING / 2));

        $promise = new Coroutine($this->datagram->receive($length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->will($this->returnCallback(function ($data) use ($length) {
                list($address, $port, $message) = $data;
                $this->assertSame(self::HOST_IPv4, $address);
                $this->assertInternalType('integer', $port);
                $this->assertGreaterThan(0, $port);
                $this->assertSame(substr(self::WRITE_STRING, 0, $length), $message);
            }));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReceiveWithLength
     */
    public function testReceiveWithInvalidLength()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
            $this->fail('Could not write to datagram.');
        }

        $promise = new Coroutine($this->datagram->receive(-1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReceive
     */
    public function testCancelReceive()
    {
        $exception = new Exception();

        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        $promise = new Coroutine($this->datagram->receive());

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
            $this->fail('Could not write to datagram.');
        }

        $promise = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->will($this->returnCallback(function ($data) {
                list($address, $port, $message) = $data;
                $this->assertSame(self::HOST_IPv4, $address);
                $this->assertInternalType('integer', $port);
                $this->assertGreaterThan(0, $port);
                $this->assertSame(self::WRITE_STRING, $message);
            }));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReceive
     */
    public function testReceiveOnEmptyDatagram()
    {
        $this->datagram = $this->createDatagram();

        $promise = new Coroutine($this->datagram->receive());

        Loop\tick(false);

        $this->assertTrue($promise->isPending());
    }

    /**
     * @depends testReceive
     */
    public function testDrainThenReceive()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        if (0 >= stream_socket_sendto($client, self::WRITE_STRING)) {
            $this->fail('Could not write to datagram.');
        }

        $promise = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->will($this->returnCallback(function ($data) {
                list($address, $port, $message) = $data;
                $this->assertSame(self::HOST_IPv4, $address);
                $this->assertInternalType('integer', $port);
                $this->assertGreaterThan(0, $port);
                $this->assertSame(self::WRITE_STRING, $message);
            }));

        $promise->done($callback);

        Loop\run();

        $string = "This is a string to write.\n";

        if (0 >= stream_socket_sendto($client, $string)) {
            $this->fail('Could not write to datagram.');
        }

        $promise = new Coroutine($this->datagram->receive());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->will($this->returnCallback(function ($data) use ($string) {
                list($address, $port, $message) = $data;
                $this->assertSame(self::HOST_IPv4, $address);
                $this->assertInternalType('integer', $port);
                $this->assertGreaterThan(0, $port);
                $this->assertSame($string, $message);
            }));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReceive
     */
    public function testReceiveWithTimeout()
    {
        $this->datagram = $this->createDatagram();

        $promise = new Coroutine($this->datagram->receive(0, self::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    public function testSend()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        $name = stream_socket_get_name($client, false);
        list($address, $port) = explode(':', $name);

        $string = "{'New String\0To Write'}\r\n";

        $promise = new Coroutine($this->datagram->send($address, $port, $string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise->done($callback);

        Loop\run();

        $data = stream_socket_recvfrom($client, self::CHUNK_SIZE);

        $this->assertSame($string, $data);
    }

    /**
     * @depends testSend
     */
    public function testSendIPv6()
    {
        $this->datagram = $this->createDatagramIPv6(self::HOST_IPv6, self::PORT);

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv6 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        $name = stream_socket_get_name($client, false);
        $colon = strrpos($name, ':');
        $address = substr($name, 0, $colon);
        $port = (int) substr($name, $colon + 1);

        $string = "{'New String\0To Write'}\r\n";

        $promise = new Coroutine($this->datagram->send($address, $port, $string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise->done($callback);

        Loop\run();

        $data = stream_socket_recvfrom($client, self::CHUNK_SIZE);

        $this->assertSame($string, $data);
    }

    /**
     * @depends testSend
     */
    public function testSendIntegerIP()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        $name = stream_socket_get_name($client, false);
        list($address, $port) = explode(':', $name);

        $address = ip2long($address);

        $string = "{'New String\0To Write'}\r\n";

        $promise = new Coroutine($this->datagram->send($address, $port, $string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise->done($callback);

        Loop\run();

        $data = stream_socket_recvfrom($client, self::CHUNK_SIZE);

        $this->assertSame($string, $data);
    }

    /**
     * @depends testSend
     */
    public function testSendAfterClose()
    {
        $this->datagram = $this->createDatagram();

        $this->datagram->close();

        $promise = new Coroutine($this->datagram->send(0, 0, self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnavailableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    public function testSendEmptyString()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        $name = stream_socket_get_name($client, false);
        list($address, $port) = explode(':', $name);

        $promise = new Coroutine($this->datagram->send($address, $port, ''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($this->datagram->send($address, $port, '0'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(1));

        $promise->done($callback);

        Loop\run();

        $data = stream_socket_recvfrom($client, self::CHUNK_SIZE);

        $this->assertSame('0', $data);
    }

    /**
     * @depends testSend
     */
    public function testSendAfterPendingSend()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        $name = stream_socket_get_name($client, false);
        list($address, $port) = explode(':', $name);

        $buffer = null;
        for ($i = 0; $i < self::CHUNK_SIZE; ++$i) {
            $buffer .= self::WRITE_STRING;
        }

        $promise = new Coroutine($this->datagram->send($address, $port, $buffer));

        $this->assertTrue($promise->isPending());

        $promise = new Coroutine($this->datagram->send($address, $port, self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testSend
     */
    public function testCloseAfterPendingSend()
    {
        $this->datagram = $this->createDatagram();

        $client = stream_socket_client(
            'udp://' . self::HOST_IPv4 . ':' . self::PORT,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT
        );

        $name = stream_socket_get_name($client, false);
        list($address, $port) = explode(':', $name);

        $buffer = null;
        for ($i = 0; $i < self::CHUNK_SIZE; ++$i) {
            $buffer .= self::WRITE_STRING;
        }

        $promise = new Coroutine($this->datagram->send($address, $port, $buffer));

        $this->assertTrue($promise->isPending());

        $this->datagram->close();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    public function testRebind()
    {
        $this->datagram = $this->createDatagram();

        $loop = $this->getMock(LoopInterface::class);

        $io = $this->getMockBuilder(Io::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loop->expects($this->once())
            ->method('poll')
            ->will($this->returnValue($io));

        $loop->expects($this->once())
            ->method('await')
            ->will($this->returnValue($io));

        Loop\loop($loop);

        $this->datagram->rebind();
    }

    /**
     * @depends testRebind
     */
    public function testRebindWhileBusy()
    {
        $this->datagram = $this->createDatagram();

        $promise = new Coroutine($this->datagram->receive());

        $poll = $this->getMockBuilder(Io::class)
            ->disableOriginalConstructor()
            ->getMock();
        $poll->expects($this->once())
            ->method('listen');

        $await = $this->getMockBuilder(Io::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loop = $this->getMock(LoopInterface::class);
        $loop->expects($this->once())
            ->method('poll')
            ->will($this->returnValue($poll));
        $loop->expects($this->once())
            ->method('await')
            ->will($this->returnValue($await));

        Loop\loop($loop);

        $this->datagram->rebind();
    }
}
