<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Stream;

use Exception;
use Icicle\Socket\Socket;
use Icicle\Stream\DuplexStreamInterface;

class DuplexStream extends Socket implements DuplexStreamInterface
{
    use ReadableStreamTrait, WritableStreamTrait {
        ReadableStreamTrait::init insteadof WritableStreamTrait;
        ReadableStreamTrait::detach insteadof WritableStreamTrait;
        ReadableStreamTrait::close insteadof WritableStreamTrait;
        ReadableStreamTrait::init as initReadable;
        ReadableStreamTrait::detach as detachReadable;
        WritableStreamTrait::init as initWritable;
        WritableStreamTrait::detach as detachWritable;
    }
    
    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        parent::__construct($socket);
        
        $this->initReadable($socket);
        $this->initWritable($socket);
    }

    /**
     * Frees resources associated with the stream and closes the stream.
     *
     * @param \Exception|null $exception Reason for the stream closing.
     */
    protected function free(Exception $exception = null)
    {
        $this->detachReadable($exception);
        $this->detachWritable($exception);
        parent::close();
    }
}
