<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket;

use Icicle\Socket\Exception\InvalidArgumentError;

abstract class Socket implements SocketInterface
{
    /**
     * Stream socket resource.
     *
     * @var resource
     */
    private $socket;
    
    /**
     * @param resource $socket PHP stream socket resource.
     *
     * @throws \Icicle\Socket\Exception\InvalidArgumentError If a non-resource is given.
     */
    public function __construct($socket)
    {
        if (!is_resource($socket)) {
            throw new InvalidArgumentError('Non-resource given to constructor!');
        }
        
        $this->socket = $socket;
        
        stream_set_blocking($this->socket, 0);
    }

    /**
     * Determines if the socket is still open.
     *
     * @return bool
     */
    public function isOpen()
    {
        return null !== $this->socket;
    }
    
    /**
     * Closes the socket.
     */
    public function close()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }
    
    /**
     * Returns the stream socket resource or null if the socket has been closed.
     *
     * @return resource|null
     */
    public function getResource()
    {
        return $this->socket;
    }

    /**
     * Parses the IP address and port of a network socket. Calls stream_socket_get_name() and then parses the returned
     * string.
     *
     * @param bool $peer True for remote IP and port, false for local IP and port.
     *
     * @return array IP address and port pair.
     *
     * @throws \Icicle\Socket\Exception\FailureException If getting the socket name fails.
     */
    protected function getName($peer = true)
    {
        return getName($this->socket, $peer);
    }
}
