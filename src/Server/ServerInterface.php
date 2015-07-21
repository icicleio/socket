<?php
namespace Icicle\Socket\Server;

use Icicle\Promise\PromiseInterface;
use Icicle\Socket\SocketInterface;

interface ServerInterface extends SocketInterface
{
    /**
     * Accepts incoming client connections.
     *
     * @return \Icicle\Promise\PromiseInterface
     *
     * @resolve \Icicle\Socket\Client\ClientInterface
     *
     * @reject \Icicle\Socket\Exception\UnavailableException If an accept request was already pending on the server.
     */
    public function accept(): PromiseInterface;
    
    /**
     * Returns the IP address or socket path on which the server is listening.
     *
     * @return string
     */
    public function getAddress(): string;
    
    /**
     * Returns the port on which the server is listening (or 0 if unix socket).
     *
     * @return int
     */
    public function getPort(): int;
}
