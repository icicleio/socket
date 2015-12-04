<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket;

use Icicle\Socket\Exception\FailureException;
use Icicle\Stream\Pipe\DuplexPipe;

class NetworkSocket extends DuplexPipe implements Socket
{
    /**
     * @var int
     */
    private $crypto = 0;
    
    /**
     * @var string
     */
    private $remoteAddress;
    
    /**
     * @var int
     */
    private $remotePort;
    
    /**
     * @var string
     */
    private $localAddress;
    
    /**
     * @var int
     */
    private $localPort;
    
    /**
     * @param resource $socket Stream socket resource.
     */
    public function __construct($socket)
    {
        parent::__construct($socket);
        
        try {
            list($this->remoteAddress, $this->remotePort) = getName($socket, true);
            list($this->localAddress, $this->localPort) = getName($socket, false);
        } catch (FailureException $exception) {
            $this->close();
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function enableCrypto(int $method, float $timeout = 0): \Generator
    {
        $resource = $this->getResource();

        if ($method & 1 || 0 === $method) {
            yield from $this->await($timeout);
        } else {
            yield from $this->poll($timeout);

            if (defined('STREAM_CRYPTO_METHOD_ANY_SERVER')) { // PHP 5.6+
                $raw = stream_socket_recvfrom($resource, 11, STREAM_PEEK);
                if (11 > strlen($raw)) {
                    throw new FailureException('Failed to read crypto handshake.');
                }

                $data = unpack('ctype/nversion/nlength/Nembed/nmax-version', $raw);
                if (0x16 !== $data['type']) {
                    throw new FailureException('Invalid crypto handshake.');
                }

                $version = $this->selectCryptoVersion($data['max-version']);
                if ($method & $version) { // Check if version was available in $method.
                    $method = $version;
                }
            }
        }

        do {
            // Error reporting suppressed since stream_socket_enable_crypto() emits E_WARNING on failure.
            $result = @stream_socket_enable_crypto($resource, (bool) $method, $method);
        } while (0 === $result && !yield from $this->poll($timeout));

        if ($result) {
            $this->crypto = $method;
            return;
        }

        $message = 'Failed to enable crypto.';
        if ($error = error_get_last()) {
            $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
        }
        throw new FailureException($message);
    }

    /**
     * Returns highest supported crypto method constant based on protocol version identifier.
     *
     * @param int $version
     *
     * @return int
     */
    private function selectCryptoVersion($version)
    {
        switch ($version) {
            case 0x300: return STREAM_CRYPTO_METHOD_SSLv3_SERVER;
            case 0x301: return STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
            case 0x302: return STREAM_CRYPTO_METHOD_TLSv1_1_SERVER;
            default:    return STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
        }
    }
    
    /**
     * @return bool
     */
    public function isCryptoEnabled(): bool
    {
        return 0 !== $this->crypto;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getRemotePort(): int
    {
        return $this->remotePort;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getLocalAddress(): string
    {
        return $this->localAddress;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getLocalPort(): int
    {
        return $this->localPort;
    }
}
