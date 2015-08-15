<?php
namespace Icicle\Socket\Server;

use Icicle\Socket\SocketInterface;

interface ServerInterface extends SocketInterface
{
    /**
     * @coroutine
     *
     * Accepts incoming client connections.
     *
     * @return \Generator
     *
     * @resolve \Icicle\Socket\Client\ClientInterface
     *
     * @throws \Icicle\Socket\Exception\BusyError If an accept request was already pending on the server.
     * @throws \Icicle\Socket\Exception\UnavailableException If the server has been closed.
     */
    public function accept();
    
    /**
     * Returns the IP address or socket path on which the server is listening.
     *
     * @return string
     */
    public function getAddress();
    
    /**
     * Returns the port on which the server is listening (or 0 if unix socket).
     *
     * @return int
     */
    public function getPort();
}
