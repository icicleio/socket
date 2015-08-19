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
use Icicle\Stream\Exception\{ClosedException, UnwritableException};
use Throwable;

trait WritableStreamTrait
{
    /**
     * Queue of data to write and promises to resolve when that data is written (or fails to write).
     * Data is stored as an array: [string, int, int|float|null, Deferred].
     *
     * @var \SplQueue
     */
    private $writeQueue;
    
    /**
     * @var bool
     */
    private $writable = true;
    
    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $await;

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
     * @param resource $socket Stream socket resource.
     */
    private function init($socket)
    {
        stream_set_write_buffer($socket, 0);
        stream_set_chunk_size($socket, SocketInterface::CHUNK_SIZE);
        
        $this->writeQueue = new \SplQueue();
        
        $this->await = $this->createAwait($socket);
    }

    /**
     * Closes the stream socket.
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Frees all resources used by the writable stream.
     *
     * @param \Throwable|null $exception
     */
    private function detach(Throwable $exception = null)
    {
        $this->writable = false;

        $this->await->free();

        while (!$this->writeQueue->isEmpty()) {
            /** @var \Icicle\Promise\Deferred $deferred */
            list( , , , $deferred) = $this->writeQueue->shift();
            $deferred->getPromise()->cancel(
                $exception = $exception ?: new ClosedException('The stream was unexpectedly closed.')
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data, float $timeout = 0): \Generator
    {
        return $this->send($data, $timeout, false);
    }

    /**
     * Writes the given data to the stream, immediately making the stream unwritable if $end is true.
     *
     * @param string $data
     * @param int $timeout
     * @param bool $end
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    private function send(string $data, float $timeout = 0, bool $end = false): \Generator
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $length = strlen($data);
        $written = 0;

        if ($end) {
            $this->writable = false;
        }

        try {
            if ($this->writeQueue->isEmpty()) {
                if (0 === $length) {
                    return $written;
                }

                $written = $this->push($this->getResource(), $data, false);

                if ($length <= $written) {
                    return $written;
                }

                $data = substr($data, $written);
            }

            $deferred = new Deferred();
            $this->writeQueue->push([$data, $written, $timeout, $deferred]);

            if (!$this->await->isPending()) {
                $this->await->listen($timeout);
            }

            return yield $deferred->getPromise();
        } catch (Throwable $exception) {
            if ($this->isOpen()) {
                $this->free($exception);
            }
            throw $exception;
        } finally {
            if ($end && $this->isOpen()) {
                $this->close();
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function end(string $data = '', float $timeout = 0): \Generator
    {
        return $this->send($data, $timeout, true);
    }

    /**
     * Returns a promise that is fulfilled when the stream is ready to receive data (output buffer is not full).
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if the data cannot be written to the stream. Use null for no timeout.
     *
     * @return \Icicle\Promise\PromiseInterface
     *
     * @resolve int Always resolves with 0.
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    protected function await(float $timeout = 0): \Generator
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }
        
        $deferred = new Deferred();
        $this->writeQueue->push(['', 0, $timeout, $deferred]);
        
        if (!$this->await->isPending()) {
            $this->await->listen($timeout);
        }

        try {
            return yield $deferred->getPromise();
        } catch (Throwable $exception) {
            if ($this->isOpen()) {
                $this->free($exception);
            }
            throw $exception;
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * @param resource $resource
     * @param string $data
     * @param bool $strict If true, fail if no bytes are written.
     *
     * @return int Number of bytes written.
     *
     * @throws FailureException If writing fails.
     */
    private function push($resource, string $data, bool $strict = false): int
    {
        // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
        $written = @fwrite($resource, $data, SocketInterface::CHUNK_SIZE);

        if (false === $written || (0 === $written && $strict)) {
            $message = 'Failed to write to stream.';
            if ($error = error_get_last()) {
                $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
            }
            throw new FailureException($message);
        }

        return $written;
    }

    /**
     * @param resource $socket
     *
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createAwait($socket): SocketEventInterface
    {
        return Loop\await($socket, function ($resource, $expired) {
            if ($expired) {
                $this->free(new TimeoutException('Writing to the socket timed out.'));
                return;
            }

            /** @var \Icicle\Promise\Deferred $deferred */
            list($data, $previous, $timeout, $deferred) = $this->writeQueue->shift();

            $length = strlen($data);

            if (0 === $length) {
                $deferred->resolve($previous);
            } else {
                try {
                    $written = $this->push($resource, $data, true);
                } catch (Throwable $exception) {
                    $deferred->reject($exception);
                    return;
                }

                if ($length <= $written) {
                    $deferred->resolve($written + $previous);
                } else {
                    $data = substr($data, $written);
                    $written += $previous;
                    $this->writeQueue->unshift([$data, $written, $timeout, $deferred]);
                }
            }

            if (!$this->writeQueue->isEmpty()) {
                list( , , $timeout) = $this->writeQueue->top();
                $this->await->listen($timeout);
            }
        });
    }
}
