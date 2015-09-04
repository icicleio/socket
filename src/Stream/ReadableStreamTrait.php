<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Socket\Stream;

use Icicle\Loop;
use Icicle\Loop\Events\SocketEventInterface;
use Icicle\Promise\{Deferred, Exception\TimeoutException};
use Icicle\Socket\{Exception\FailureException, SocketInterface};
use Icicle\Stream\Exception\{BusyError, ClosedException, UnreadableException};
use Icicle\Stream\{PipeTrait, Structures\Buffer};
use Throwable;

trait ReadableStreamTrait
{
    use PipeTrait;

    /**
     * @var \Icicle\Promise\Deferred|null
     */
    private $deferred;
    
    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $poll;
    
    /**
     * @var int
     */
    private $length = 0;
    
    /**
     * @var string|null
     */
    private $byte;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * Determines if the stream is still open.
     *
     * @return bool
     */
    abstract public function isOpen(): bool;
    
    /**
     * @return resource Stream socket resource.
     */
    abstract protected function getResource();

    /**
     * Frees resources associated with the stream and closes the stream.
     *
     * @param \Throwable|null $exception
     */
    abstract protected function free(Throwable $exception = null);

    /**
     * Closes the stream socket.
     */
    public function close()
    {
        $this->free();
    }

    /**
     * @param resource $socket Stream socket resource.
     */
    private function init($socket)
    {
        stream_set_read_buffer($socket, 0);
        stream_set_chunk_size($socket, SocketInterface::CHUNK_SIZE);
    }
    
    /**
     * Frees all resources used by the writable stream.
     *
     * @param \Throwable|null $exception
     */
    private function detach(Throwable $exception = null)
    {
        if (null !== $this->poll) {
            $this->poll->free();
        }

        if (null !== $this->deferred) {
            $this->deferred->getPromise()->cancel(
                $exception ?: new ClosedException('The stream was unexpectedly closed.')
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 0, string $byte = null, float $timeout = 0): \Generator
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on stream.');
        }
        
        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        $this->length = $length;
        if (0 >= $this->length) {
            $this->length = SocketInterface::CHUNK_SIZE;
        }

        $this->byte = strlen($byte) ? $byte[0] : null;

        $resource = $this->getResource();
        $data = $this->fetch($resource);

        if ('' !== $data) {
            return $data;
        }

        if ($this->eof($resource)) { // Close only if no data was read and at EOF.
            $this->close();
            return $data; // Resolve with empty string on EOF.
        }

        if (null === $this->poll) {
            $this->poll = $this->createPoll();
        }

        $this->poll->listen($timeout);

        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            return yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
        }
    }
    
    /**
     * Returns a promise that is fulfilled when there is data available to read in the internal stream buffer. Note that
     * this method does not consider data that may be available in the internal buffer. This method should be used to
     * implement functionality that uses the stream socket resource directly.
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use null for no timeout.
     *
     * @return \Icicle\Promise\PromiseInterface
     *
     * @resolve string Empty string.
     *
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Socket\Exception\FailureException If the stream buffer is not empty.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    protected function poll(float $timeout = 0): \Generator
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on stream.');
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        if ('' !== $this->buffer) {
            throw new FailureException('Stream buffer is not empty. Perform another read before polling.');
        }

        $this->length = 0;

        if (null === $this->poll) {
            $this->poll = $this->createPoll();
        }

        $this->poll->listen($timeout);

        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            return yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        return $this->isOpen();
    }

    /**
     * Reads data from the stream socket resource based on set length and read-to byte.
     *
     * @param resource $resource
     *
     * @return string
     */
    private function fetch($resource): string
    {
        if ('' === $this->buffer) {
            $data = (string) fread($resource, $this->length);

            if (null === $this->byte || '' === $data) {
                return $data;
            }

            $this->buffer = $data;
        }

        if (null !== $this->byte && false !== ($position = strpos($this->buffer, $this->byte))) {
            ++$position; // Include byte in result.
        } else {
            $position = $this->length;
        }

        $data = (string) substr($this->buffer, 0, $position);
        $this->buffer = (string) substr($this->buffer, $position);
        return $data;
    }

    /**
     * @param resource $resource
     *
     * @return bool
     */
    private function eof($resource): bool
    {
        return feof($resource) && '' === $this->buffer;
    }

    /**
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createPoll(): SocketEventInterface
    {
        return Loop\poll($this->getResource(), function ($resource, $expired) {
            if ($expired) {
                $this->deferred->reject(new TimeoutException('The connection timed out.'));
                return;
            }

            if (0 === $this->length) {
                $this->deferred->resolve('');
                return;
            }

            $data = $this->fetch($resource);

            $this->deferred->resolve($data);

            if ('' === $data && $this->eof($resource)) { // Close only if no data was read and at EOF.
                $this->close();
            }
        });
    }
}
