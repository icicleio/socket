<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Stream;

use Icicle\Socket\Socket;
use Icicle\Stream\ReadableStreamInterface;

class ReadableStream extends Socket implements ReadableStreamInterface
{
    use ReadableStreamTrait;
    
    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        parent::__construct($socket);
        $this->init($socket);
    }

    /**
     * Frees resources associated with the stream and closes the stream.
     *
     * @param \Throwable $exception Reason for the stream closing.
     */
    protected function free(\Throwable $exception = null)
    {
        $this->detach($exception);
        parent::close();
    }
}
