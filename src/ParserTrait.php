<?php
namespace Icicle\Socket;

trait ParserTrait
{
    /**
     * Parses a name of the format ip:port, returning an array containing the ip and port.
     *
     * @param string $name
     *
     * @return array [ip-address, port] or [socket-path, 0].
     */
    protected function parseName($name)
    {
        $colon = strrpos($name, ':');

        if (false === $colon) { // Unix socket.
            return [$name, 0];
        }

        $address = trim(substr($name, 0, $colon), '[]');
        $port = (int) substr($name, $colon + 1);

        $address = $this->parseAddress($address);

        return [$address, $port];
    }

    /**
     * Formats given address into a string. Converts integer to IPv4 address, wraps IPv6 address in brackets.
     *
     * @param string $address
     *
     * @return string
     */
    protected function parseAddress($address)
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
    protected function makeName($address, $port)
    {
        if (-1 === $port) { // Unix socket.
            return $address;
        }

        return sprintf('%s:%d', $this->parseAddress($address), $port);
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
    protected function makeUri($protocol, $address, $port)
    {
        if (-1 === $port) { // Unix socket.
            return sprintf('%s://%s', $protocol, $address);
        }

        return sprintf('%s://%s:%d', $protocol, $this->parseAddress($address), $port);
    }
}
