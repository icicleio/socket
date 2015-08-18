<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Stream;

use Icicle\Loop;
use Icicle\Promise;
use Icicle\Socket\Stream\ReadableStream;
use Icicle\Socket\Stream\WritableStream;

class ReadableStreamTest extends StreamTest
{
    use ReadableStreamTestTrait;
    
    public function createStreams()
    {
        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        
        $readable = new ReadableStream($read);
        
        $writable = $this->getMockBuilder(WritableStream::class)
                         ->disableOriginalConstructor()
                         ->getMock();
        
        stream_set_blocking($write, 0);
        
        $writable->method('getResource')
                 ->will($this->returnValue($write));
        
        $writable->method('isWritable')
                 ->will($this->returnValue(true));
        
        $writable->method('write')
            ->will($this->returnCallback(function ($data) use ($write) {
                $length = strlen($data);
                if ($length) {
                    fwrite($write, $data);
                }
                yield $length;
            }));
        
        $writable->method('close')
                 ->will($this->returnCallback(function () use ($write) {
                     fclose($write);
                 }));
        
        return [$readable, $writable];
    }
}