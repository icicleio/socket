<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket;

use Icicle\Stream\DuplexStream;
use Icicle\Stream\Resource;

interface Socket extends DuplexStream, Resource
{
    /**
     * @coroutine
     *
     * @param int $method One of the server crypto flags, e.g. STREAM_CRYPTO_METHOD_TLS_SERVER for incoming (remote)
     *     clients, STREAM_CRYPTO_METHOD_TLS_CLIENT for outgoing (local) clients.
     * @param int|float $timeout Seconds to wait between reads/writes to enable crypto before failing.
     *
     * @return \Generator
     *
     * @resolve null
     *
     * @throws \Icicle\Socket\Exception\FailureException If enabling crypto fails.
     * @throws \Icicle\Stream\Exception\ClosedException If the socket has been closed.
     */
    public function enableCrypto($method, $timeout = 0);
    
    /**
     * Determines if cyrpto is enabled.
     *
     * @return bool
     */
    public function isCryptoEnabled();

    /**
     * Shifts the given data back to the front of the socket stream. The data will be the first bytes returned from any
     * pending or subsequent read.
     *
     * @param string $data
     */
    public function unshift($data);

    /**
     * Returns the remote IP or socket path as a string representation.
     *
     * @return string
     */
    public function getRemoteAddress();
    
    /**
     * Returns the remote port number (or 0 if unix socket).
     *
     * @return int
     */
    public function getRemotePort();
    
    /**
     * Returns the local IP or socket path as a string representation.
     *
     * @return string
     */
    public function getLocalAddress();
    
    /**
     * Returns the local port number (or 0 if unix socket).
     *
     * @return int
     */
    public function getLocalPort();
}
