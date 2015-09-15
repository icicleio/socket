<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket;

interface SocketInterface
{
    const CHUNK_SIZE = 8192; // 8kB
    
    /**
     * Determines if the socket is still open.
     *
     * @return bool
     */
    public function isOpen();
    
    /**
     * Closes the socket, making it unreadable or unwritable.
     */
    public function close();
}
