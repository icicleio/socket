<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket;

use Icicle\Socket\Exception\FailureException;

if (!function_exists(__NAMESPACE__ . '\pair')) {
    /**
     * Returns a pair of connected unix domain stream socket resources.
     *
     * @return resource[] Pair of socket resources.
     *
     * @throws \Icicle\Socket\Exception\FailureException If creating the sockets fails.
     */
    function pair()
    {
        if (false === ($sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP))) {
            $message = 'Failed to create socket pair.';
            if ($error = error_get_last()) {
                $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
            }
            throw new FailureException($message);
        }

        return $sockets;
    }

    /**
     * Parses a name of the format ip:port, returning an array containing the ip and port.
     *
     * @param string $name
     *
     * @return array [ip-address, port] or [socket-path, 0].
     */
    function parseName($name)
    {
        $colon = strrpos($name, ':');

        if (false === $colon) { // Unix socket.
            return [$name, 0];
        }

        $address = trim(substr($name, 0, $colon), '[]');
        $port = (int) substr($name, $colon + 1);

        $address = parseAddress($address);

        return [$address, $port];
    }

    /**
     * Formats given address into a string. Converts integer to IPv4 address, wraps IPv6 address in brackets.
     *
     * @param string $address
     *
     * @return string
     */
    function parseAddress($address)
    {
        if (false !== strpos($address, ':')) { // IPv6 address
            return '[' . trim($address, '[]') . ']';
        }

        return $address;
    }

    /**
     * Creates string of format $address[:$port].
     *
     * @param string $address Address or path.
     * @param int $port Port number or null for unix socket.
     *
     * @return string
     */
    function makeName($address, $port)
    {
        if (-1 === $port) { // Unix socket.
            return $address;
        }

        return sprintf('%s:%d', parseAddress($address), $port);
    }

    /**
     * Creates string of format $protocol://$address[:$port].
     *
     * @param string $protocol Protocol.
     * @param string $address Address or path.
     * @param int $port Port number or null for unix socket.
     *
     * @return string
     */
    function makeUri($protocol, $address, $port)
    {
        if (-1 === $port) { // Unix socket.
            return sprintf('%s://%s', $protocol, $address);
        }

        return sprintf('%s://%s:%d', $protocol, parseAddress($address), $port);
    }

    /**
     * Parses the IP address and port of a network socket. Calls stream_socket_get_name() and then parses the returned
     * string.
     *
     * @param resource $socket Stream socket resource.
     * @param bool $peer True for remote IP and port, false for local IP and port.
     *
     * @return array IP address and port pair.
     *
     * @throws \Icicle\Socket\Exception\FailureException If getting the socket name fails.
     */
    function getName($socket, $peer = true)
    {
        // Error reporting suppressed since stream_socket_get_name() emits an E_WARNING on failure (checked below).
        $name = @stream_socket_get_name($socket, (bool) $peer);

        if (false === $name) {
            $message = 'Could not get socket name.';
            if ($error = error_get_last()) {
                $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
            }
            throw new FailureException($message);
        }

        return parseName($name);
    }
}