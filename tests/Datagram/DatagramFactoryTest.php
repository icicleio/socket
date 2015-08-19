<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Datagram;

use Icicle\Loop;
use Icicle\Socket\Datagram\Datagram;
use Icicle\Socket\Datagram\DatagramInterface;
use Icicle\Socket\Datagram\DatagramFactory;
use Icicle\Tests\Socket\TestCase;

class DatagramFactoryTest extends TestCase
{
    const HOST_IPv4 = '127.0.0.1';
    const HOST_IPv6 = '[::1]';
    const PORT = 51337;
    const CONNECT_TIMEOUT = 1;
    
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    
    protected $factory;
    
    protected $datagram;
    
    public function setUp()
    {
        $this->factory = new DatagramFactory();
    }
    
    public function tearDown()
    {
        Loop\clear();
        
        if ($this->datagram instanceof Datagram) {
            $this->datagram->close();
        }
    }
    
    public function testCreate()
    {
        $this->datagram = $this->factory->create(self::HOST_IPv4, self::PORT);
        
        $this->assertInstanceOf(DatagramInterface::class, $this->datagram);
        
        $this->assertSame(self::HOST_IPv4, $this->datagram->getAddress());
        $this->assertSame(self::PORT, $this->datagram->getPort());
    }
    
    public function testCreateIPv6()
    {
        $this->datagram = $this->factory->create(self::HOST_IPv6, self::PORT);
        
        $this->assertInstanceOf(DatagramInterface::class, $this->datagram);
        
        $this->assertSame(self::HOST_IPv6, $this->datagram->getAddress());
        $this->assertSame(self::PORT, $this->datagram->getPort());
    }
    
    /**
     * @medium
     * @depends testCreate
     * @expectedException \Icicle\Socket\Exception\FailureException
     */
    public function testCreateInvalidHost()
    {
        $this->datagram = $this->factory->create('invalid.host', self::PORT);
        
        $this->datagram->close();
    }
}
