<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Server;

use Icicle\Exception\InvalidArgumentError;
use Icicle\Socket;
use Icicle\Socket\Exception\FailureException;

class DefaultServerFactory implements ServerFactory
{
    const DEFAULT_BACKLOG = 128;

    // Verify peer should normally be off on the server side.
    const DEFAULT_VERIFY_PEER = false;
    const DEFAULT_ALLOW_SELF_SIGNED = false;
    const DEFAULT_VERIFY_DEPTH = 10;

    /**
     * {@inheritdoc}
     */
    public function create(string $host, int $port = null, array $options = []): Server
    {
        $protocol = (string) ($options['protocol'] ?? (null === $port ? 'unix' : 'tcp'));
        $autoClose = (bool) ($options['auto_close'] ?? true);
        $queue = (int) ($options['backlog'] ?? (defined('SOMAXCONN') ? SOMAXCONN : self::DEFAULT_BACKLOG));
        $pem = (string) ($options['pem'] ?? '');
        $passphrase = ($options['passphrase'] ?? '');
        $name = (string) ($options['name'] ?? '');

        $verify = (string) ($options['verify_peer'] ?? self::DEFAULT_VERIFY_PEER);
        $allowSelfSigned = (bool) ($options['allow_self_signed'] ?? self::DEFAULT_ALLOW_SELF_SIGNED);
        $verifyDepth = (int) ($options['verify_depth'] ?? self::DEFAULT_VERIFY_DEPTH);

        $context = [];

        $context['socket'] = [
            'bindto' => Socket\makeName($host, $port),
            'backlog' => $queue,
            'ipv6_v6only' => true,
            'so_reuseaddr' => (bool) ($options['reuseaddr'] ?? false),
            'so_reuseport' => (bool) ($options['reuseport'] ?? false),
        ];

        if ('' !== $pem) {
            if (!file_exists($pem)) {
                throw new InvalidArgumentError('No file found at given PEM path.');
            }

            $context['ssl'] = [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => $allowSelfSigned,
                'verify_depth' => $verifyDepth,
                'local_cert' => $pem,
                'disable_compression' => true,
                'SNI_enabled' => true,
                'SNI_server_name' => $name,
                'peer_name' => $name,
            ];

            if ('' !== $passphrase) {
                $context['ssl']['passphrase'] = $passphrase;
            }
        }

        $context = stream_context_create($context);

        $uri = Socket\makeUri($protocol, $host, $port);
        // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
        $socket = @stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if (!$socket || $errno) {
            throw new FailureException(sprintf('Could not create server %s: Errno: %d; %s', $uri, $errno, $errstr));
        }

        return new BasicServer($socket, $autoClose);
    }
}
