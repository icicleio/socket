<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Connector;

use Icicle\Loop;
use Icicle\Promise\Promise;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Socket;
use Icicle\Socket\Socket as ClientSocket;
use Icicle\Socket\Exception\InvalidArgumentError;
use Icicle\Socket\Exception\FailureException;

class Connector implements ConnectorInterface
{
    const DEFAULT_CONNECT_TIMEOUT = 10;
    const DEFAULT_ALLOW_SELF_SIGNED = false;
    const DEFAULT_VERIFY_DEPTH = 10;

    /**
     * {@inheritdoc}
     */
    public function connect($ip, $port = null, array $options = [])
    {
        $protocol = isset($options['protocol'])
            ? (string) $options['protocol']
            : (null === $port ? 'unix' : 'tcp');
        $allowSelfSigned = isset($options['allow_self_signed'])
            ? (bool) $options['allow_self_signed']
            : self::DEFAULT_ALLOW_SELF_SIGNED;
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_CONNECT_TIMEOUT;
        $verifyDepth = isset($options['verify_depth']) ? (int) $options['verify_depth'] : self::DEFAULT_VERIFY_DEPTH;
        $cafile = isset($options['cafile']) ? (string) $options['cafile'] : null;
        $name = isset($options['name']) ? (string) $options['name'] : null;
        $cn = isset($options['cn']) ? (string) $options['cn'] : $name;
        
        $context = [];
        
        $context['socket'] = [
            'connect' => Socket\makeName($ip, $port),
        ];

        $context['ssl'] = [
            'capture_peer_cert' => true,
            'capture_peer_chain' => true,
            'capture_peer_cert_chain' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => $allowSelfSigned,
            'verify_depth' => $verifyDepth,
            'CN_match' => $cn,
            'SNI_enabled' => true,
            'SNI_server_name' => $name,
            'peer_name' => $name,
            'disable_compression' => true,
        ];
        
        if (null !== $cafile) {
            if (!file_exists($cafile)) {
                throw new InvalidArgumentError('No file exists at path given for cafile.');
            }
            $context['ssl']['cafile'] = $cafile;
        }

        $context = stream_context_create($context);
        
        $uri = Socket\makeUri($protocol, $ip, $port);
        // Error reporting suppressed since stream_socket_client() emits an E_WARNING on failure (checked below).
        $socket = @stream_socket_client(
            $uri,
            $errno,
            $errstr,
            null, // Timeout does not apply for async connect. Timeout enforced by await below.
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );
        
        if (!$socket || $errno) {
            throw new FailureException(
                sprintf('Could not connect to %s; Errno: %d; %s', $uri, $errno, $errstr)
            );
        }
        
        yield new Promise(function (callable $resolve, callable $reject) use ($socket, $timeout) {
            $await = Loop\await($socket, function ($resource, $expired) use (&$await, $resolve, $reject) {
                /** @var \Icicle\Loop\Events\SocketEventInterface $await */
                $await->free();
                
                if ($expired) {
                    $reject(new TimeoutException('Connection attempt timed out.'));
                    return;
                }

                $resolve(new ClientSocket($resource));
            });
            
            $await->listen($timeout);

            return function () use ($await) {
                $await->free();
            };
        });
    }
}
