<?php
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
