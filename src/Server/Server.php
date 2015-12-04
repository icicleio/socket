<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Server;

use Icicle\Stream\Resource;

interface Server extends Resource
{
    /**
     * @coroutine
     *
     * Accepts incoming client connections.
     *
     * @return \Generator
     *
     * @resolve \Icicle\Socket\Client\ClientInterface
     *
     * @throws \Icicle\Socket\Exception\BusyError If an accept request was already pending on the server.
     * @throws \Icicle\Socket\Exception\UnavailableException If the server has been closed.
     */
    public function accept();
    
    /**
     * Returns the IP address or socket path on which the server is listening.
     *
     * @return string
     */
    public function getAddress();
    
    /**
     * Returns the port on which the server is listening (or 0 if unix socket).
     *
     * @return int
     */
    public function getPort();
}
