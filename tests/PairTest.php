<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket;

use Icicle\Socket;

class PairTest extends TestCase
{
    public function testPair()
    {
        $socket = Socket\pair();

        $this->assertInternalType('resource', $socket[0]);
        $this->assertInternalType('resource', $socket[1]);

        $this->assertSame('stream', get_resource_type($socket[0]));
        $this->assertSame('stream', get_resource_type($socket[1]));

        $string = 'test';

        fwrite($socket[0], $string);
        $this->assertSame($string, fread($socket[1], 8192));
    }
}
