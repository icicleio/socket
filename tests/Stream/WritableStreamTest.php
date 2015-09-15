<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Stream;

use Icicle\Loop;
use Icicle\Promise;
use Icicle\Socket\Stream\ReadableStream;
use Icicle\Socket\Stream\WritableStream;

class WritableStreamTest extends StreamTest
{
    use WritableStreamTestTrait;
    
    public function createStreams()
    {
        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        
        $readable = $this->getMockBuilder(ReadableStream::class)
                         ->disableOriginalConstructor()
                         ->getMock();
        
        stream_set_blocking($read, 0);
        
        $readable->method('getResource')
                 ->will($this->returnValue($read));
        
        $readable->method('isReadable')
                 ->will($this->returnValue(true));
        
        $readable->method('read')
            ->will($this->returnCallback(function ($length = 0) use ($read) {
                if (0 === $length) {
                    $length = 8192;
                }
                yield fread($read, $length);
            }));

        $readable->method('close')
                 ->will($this->returnCallback(function () use ($read) {
                     fclose($read);
                 }));
        
        $writable = new WritableStream($write);
        
        return [$readable, $writable];
    }
    
}