<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Datagram;

use Icicle\Socket\SocketInterface;

interface DatagramInterface extends SocketInterface
{
    /**
     * @return string
     */
    public function getAddress(): string;
    
    /**
     * @return int
     */
    public function getPort(): int;
    
    /**
     * @coroutine
     *
     * @param int $length
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve [string, int, string] Array containing the senders remote address, remote port, and data received.
     *
     * @throws \Icicle\Socket\Exception\BusyError If a read was already pending on the datagram.
     * @throws \Icicle\Socket\Exception\UnavailableException If the datagram is no longer readable.
     * @throws \Icicle\Socket\Exception\ClosedException If the datagram has been closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If receiving times out.
     * @throws \Icicle\Socket\Exception\FailureException If receiving fails.
     */
    public function receive(int $length = 0, float $timeout = 0): \Generator;

    /**
     * @coroutine
     *
     * @param int|string $address IP address of receiver.
     * @param int $port Port of receiver.
     * @param string $data Data to send.
     *
     * @return \Generator
     *
     * @resolve int Number of bytes written.
     *
     * @throws \Icicle\Socket\Exception\UnavailableException If the datagram is no longer writable.
     * @throws \Icicle\Socket\Exception\ClosedException If the datagram closes.
     * @throws \Icicle\Promise\Exception\TimeoutException If sending the data times out.
     * @throws \Icicle\Socket\Exception\FailureException If sending data fails.
     */
    public function send($address, int $port, string $data): \Generator;
}
