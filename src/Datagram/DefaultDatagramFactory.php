<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Datagram;

use Icicle\Socket;
use Icicle\Socket\Exception\FailureException;

class DefaultDatagramFactory implements DatagramFactory
{
    /**
     * {@inheritdoc}
     */
    public function create($host, $port = null, array $options = [])
    {
        $context = [];
        
        $context['socket'] = [];
        $context['socket']['bindto'] = Socket\makeName($host, $port);
        
        $context = stream_context_create($context);
        
        $uri = Socket\makeUri('udp', $host, $port);
        // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
        $socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $context);
        
        if (!$socket || $errno) {
            throw new FailureException(
                sprintf('Could not create datagram on %s: Errno: %d; %s', $uri, $errno, $errstr)
            );
        }
        
        return new BasicDatagram($socket);
    }
}
