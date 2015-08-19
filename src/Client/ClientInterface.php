<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Client;

use Icicle\Socket\SocketInterface;
use Icicle\Stream\DuplexStreamInterface;

interface ClientInterface extends SocketInterface, DuplexStreamInterface
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
     * @resolve $this
     *
     * @reject \Icicle\Socket\Exception\FailureException If enabling crypto fails.
     * @reject \Icicle\Socket\Exception\ClosedException If the client has been closed.
     * @reject \Icicle\Socket\Exception\BusyError If the client was already busy waiting to read.
     */
    public function enableCrypto(int $method, float $timeout = 0): \Generator;
    
    /**
     * Determines if cyrpto has been enabled.
     *
     * @return bool
     */
    public function isCryptoEnabled(): bool;
    
    /**
     * Returns the remote IP or socket path as a string representation.
     *
     * @return string
     */
    public function getRemoteAddress(): string;
    
    /**
     * Returns the remote port number (or 0 if unix socket).
     *
     * @return int
     */
    public function getRemotePort(): int;
    
    /**
     * Returns the local IP or socket path as a string representation.
     *
     * @return string
     */
    public function getLocalAddress(): string;
    
    /**
     * Returns the local port number (or 0 if unix socket).
     *
     * @return int
     */
    public function getLocalPort(): int;
}
