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
    public function enableCrypto($method, $timeout = 0)
    {
        $method = (int) $method;

        yield $this->await($timeout);

        $resource = $this->getResource();

        do {
            // Error reporting suppressed since stream_socket_enable_crypto() emits E_WARNING on failure.
            $result = @stream_socket_enable_crypto($resource, (bool) $method, $method);
        } while (0 === $result && !(yield $this->poll($timeout)));

        if ($result) {
            $this->crypto = $method;
            yield $this;
            return;
        }

        $message = 'Failed to enable crypto.';
        if ($error = error_get_last()) {
            $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
        }
        throw new FailureException($message);
    }
    
    /**
     * @return bool
     */
    public function isCryptoEnabled()
    {
        return 0 !== $this->crypto;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getRemoteAddress()
    {
        return $this->remoteAddress;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getRemotePort()
    {
        return $this->remotePort;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getLocalAddress()
    {
        return $this->localAddress;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getLocalPort()
    {
        return $this->localPort;
    }
}
