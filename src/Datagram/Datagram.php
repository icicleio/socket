<?php
namespace Icicle\Socket\Datagram;

use Exception;
use Icicle\Loop;
use Icicle\Promise\Deferred;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Socket\Exception\BusyError;
use Icicle\Socket\Exception\ClosedException;
use Icicle\Socket\Exception\FailureException;
use Icicle\Socket\Exception\UnavailableException;
use Icicle\Socket\Socket;
use Icicle\Stream\ParserTrait;
use Icicle\Stream\Structures\Buffer;

class Datagram extends Socket implements DatagramInterface
{
    use ParserTrait;

    /**
     * @var string
     */
    private $address;
    
    /**
     * @var int
     */
    private $port;
    
    /**
     * @var \Icicle\Promise\Deferred|null
     */
    private $deferred;
    
    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $poll;
    
    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $await;
    
    /**
     * @var \SplQueue
     */
    private $writeQueue;
    
    /**
     * @var int
     */
    private $length = 0;
    
    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        parent::__construct($socket);
        
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);
        stream_set_chunk_size($socket, self::CHUNK_SIZE);
        
        $this->writeQueue = new \SplQueue();
        
        $this->poll = $this->createPoll($socket);
        $this->await = $this->createAwait($socket);
        
        try {
            list($this->address, $this->port) = $this->getName(false);
        } catch (FailureException $exception) {
            $this->free($exception);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Frees resources associated with the datagram and closes the datagram.
     *
     * @param \Exception|null $exception Reason for closing the datagram.
     */
    protected function free(Exception $exception = null)
    {
        $this->poll->free();
        $this->await->free();

        if (null !== $this->deferred) {
            $this->deferred->getPromise()->cancel(
                $exception = $exception ?: new ClosedException('The datagram was unexpectedly closed.')
            );
        }

        while (!$this->writeQueue->isEmpty()) {
            /** @var \Icicle\Promise\Deferred $deferred */
            list( , , , $deferred) = $this->writeQueue->shift();
            $deferred->getPromise()->cancel(
                $exception = $exception ?: new ClosedException('The datagram was unexpectedly closed.')
            );
        }

        parent::close();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getAddress()
    {
        return $this->address;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getPort()
    {
        return $this->port;
    }
    
    /**
     * {@inheritdoc}
     */
    public function receive($length = 0, $timeout = 0)
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on datagram.');
        }
        
        if (!$this->isOpen()) {
            throw new UnavailableException('The datagram is no longer readable.');
        }

        $this->length = $this->parseLength($length);
        if (0 === $this->length) {
            $this->length = self::CHUNK_SIZE;
        }

        $this->poll->listen($timeout);
        
        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send($address, $port, $data)
    {
        if (!$this->isOpen()) {
            throw new UnavailableException('The datagram is no longer writable.');
        }
        
        $data = new Buffer($data);
        $written = 0;
        $peer = $this->makeName($address, $port);
        
        if ($this->writeQueue->isEmpty()) {
            if ($data->isEmpty()) {
                yield $written;
                return;
            }

            $written = $this->sendTo($this->getResource(), $data, $peer, false);

            if ($data->getLength() <= $written) {
                yield $written;
                return;
            }
            
            $data->remove($written);
        }

        $deferred = new Deferred();
        $this->writeQueue->push([$data, $written, $peer, $deferred]);
        
        if (!$this->await->isPending()) {
            $this->await->listen();
        }

        try {
            yield $deferred->getPromise();
        } catch (Exception $exception) {
            if ($this->isOpen()) {
                $this->free($exception);
            }
            throw $exception;
        }
    }

    /**
     * @param resource $socket Stream socket resource.
     *
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createPoll($socket)
    {
        return Loop\poll($socket, function ($resource, $expired) {
            try {
                if ($expired) {
                    throw new TimeoutException('The datagram timed out.');
                }

                $data = stream_socket_recvfrom($resource, $this->length, 0, $peer);

                // Having difficulty finding a test to cover this scenario, but the check seems appropriate.
                if (false === $data) { // Reading failed, so close datagram.
                    $message = 'Failed to read from datagram.';
                    if ($error = error_get_last()) {
                        $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
                    }
                    throw new FailureException($message);
                }

                list($address, $port) = $this->parseName($peer);

                $result = [$address, $port, $data];

                $this->deferred->resolve($result);
            } catch (Exception $exception) {
                $this->deferred->reject($exception);
            }
        });
    }
    
    /**
     * @param resource $socket Stream socket resource.
     *
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createAwait($socket)
    {
        return Loop\await($socket, function ($resource) use (&$onWrite) {
            /**
             * @var \Icicle\Stream\Structures\Buffer $data
             * @var \Icicle\Promise\Deferred $deferred
             */
            list($data, $previous, $peer, $deferred) = $this->writeQueue->shift();
            
            if ($data->isEmpty()) {
                $deferred->resolve($previous);
            } else {
                try {
                    $written = $this->sendTo($resource, $data, $peer, true);
                } catch (Exception $exception) {
                    $deferred->reject($exception);
                    return;
                }

                if ($data->getLength() <= $written) {
                    $deferred->resolve($written + $previous);
                } else {
                    $data->remove($written);
                    $written += $previous;
                    $this->writeQueue->unshift([$data, $written, $peer, $deferred]);
                }
            }
            
            if (!$this->writeQueue->isEmpty()) {
                $this->await->listen();
            }
        });
    }

    /**
     * @param resource $resource
     * @param Buffer $data
     * @param string $peer
     * @param bool $strict If true, fail if no bytes are written.
     *
     * @return int Number of bytes written.
     *
     * @throws FailureException If sending the data fails.
     */
    private function sendTo($resource, Buffer $data, $peer, $strict = false)
    {
        $written = stream_socket_sendto($resource, $data->peek(self::CHUNK_SIZE), 0, $peer);

        // Having difficulty finding a test to cover this scenario, but the check seems appropriate.
        if (false === $written || -1 === $written || (0 === $written && $strict)) {
            $message = 'Failed to write to datagram.';
            if ($error = error_get_last()) {
                $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
            }
            throw new FailureException($message);
        }

        return $written;
    }
}
