<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Stream;

use Icicle\Socket\Stream\DuplexStream;

class DuplexStreamTest extends StreamTest
{
    use ReadableStreamTestTrait,
        WritableStreamTestTrait;

    public function createStreams()
    {
        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $readable = new DuplexStream($read);
        $writable = new DuplexStream($write);
        
        return [$readable, $writable];
    }
}
