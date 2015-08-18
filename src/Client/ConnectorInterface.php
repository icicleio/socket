<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Client;

interface ConnectorInterface
{
    /**
     * @coroutine
     *
     * @param string $host IP address or unix socket path. (Using a domain name will cause a blocking DNS
     *     resolution. Use the DNS component to perform non-blocking DNS resolution.)
     * @param int $port Port number or -1 for unix socket.
     * @param mixed[] $options {
     *     @var string $protocol The protocol to use, such as tcp, udp, s3, ssh. Defaults to tcp.
     *     @var int|float $timeout Number of seconds until connection attempt times out. Defaults to 10 seconds.
     *     @var string $name Name to verify certificate. May match CN or SAN names on certificate. (PHP 5.6+)
     *     @var string $cn Name to verify certificate. Must match CN exactly. (PHP 5.5) (e.g., '*.google.com').
     *     @var bool $allow_self_signed Set to true to allow self-signed certificates. Defaults to false.
     *     @var int $verify_depth Max levels of certificate authorities the verifier will transverse. Defaults to 10.
     *     @var string cafile Path to bundle of root certificates to verify against.
     * }
     *
     * @return \Generator
     *
     * @resolve \Icicle\Socket\Client\ClientInterface Fulfilled once the connection is established.
     *
     * @reject \Icicle\Socket\Exception\FailureException If connecting fails.
     * @reject \Icicle\Socket\Exception\InvalidArgumentError If a CA file does not exist at the path given.
     * @reject \Icicle\Socket\Exception\TimeoutException If the connection attempt times out.
     *
     * @see http://curl.haxx.se/docs/caextract.html Contains links to download bundle of CA Root Certificates that
     *     may be used for the cafile option if needed.
     */
    public function connect($host, $port, array $options = []);
}
