<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket;

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Socket;
use Icicle\Socket\Connector\Connector;
use Icicle\Socket\Socket as SocketInterface;
use Icicle\Tests\Socket\Connector\DefaultConnectorTest as ConnectorTest;

class FunctionsTest extends TestCase
{
    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }

    public function testConnector()
    {
        $connector = Socket\connector();

        $this->assertInstanceOf(Connector::class, $connector);
    }

    public function testConnect()
    {
        $server = (new ConnectorTest())->createServer();

        $promise = new Coroutine(Socket\connect(ConnectorTest::HOST_IPv4, ConnectorTest::PORT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(SocketInterface::class));

        $promise->done($callback);

        $promise->done(function (SocketInterface $socket) {
            $this->assertTrue($socket->isOpen());
            $this->assertSame($socket->getLocalAddress(), ConnectorTest::HOST_IPv4);
            $this->assertSame($socket->getRemoteAddress(), ConnectorTest::HOST_IPv4);
            $this->assertInternalType('integer', $socket->getLocalPort());
            $this->assertSame($socket->getRemotePort(), ConnectorTest::PORT);
        });

        Loop\run();

        fclose($server);
    }
}
