<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Server;

interface ServerFactory
{
    /**
     * Creates a server on the given host and port.
     *
     * Note: Current CA file in PEM format can be downloaded from http://curl.haxx.se/ca/cacert.pem
     *
     * @param string $host IP address or unix socket path.
     * @param int|null $port Port number or null for unix socket.
     * @param mixed[] $options {
     *     @var int $backlog Connection backlog size. Note that operating system setting SOMAXCONN may set an upper
     *     limit and may need to be changed to allow a larger backlog size.
     *     @var string $pem Path to PEM file containing certificate and private key to enable SSL on client connections.
     *     @var string $passphrase PEM passphrase if applicable.
     *     @var string $name Name to use as SNI identifier. If not set, name will be guessed based on $host.
     *     @var bool $verify_peer True to verify client certificate. Normally should be false on the server.
     *     @var bool $allow_self_signed Set to true to allow self-signed certificates. Defaults to false.
     *     @var int $verify_depth Max levels of certificate authorities the verifier will transverse. Defaults to 10.
     * }
     *
     * @return \Icicle\Socket\Server\Server
     *
     * @throws \Icicle\Exception\InvalidArgumentError If PEM file path given does not exist.
     * @throws \Icicle\Socket\Exception\FailureException If the server socket could not be created.
     */
    public function create($host, $port = null, array $options = []);
}
