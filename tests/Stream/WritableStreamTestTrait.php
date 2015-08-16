<?php
namespace Icicle\Tests\Socket\Stream;

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Socket\Exception\FailureException;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\UnwritableException;

trait WritableStreamTestTrait
{
    /**
     * @return \Icicle\Stream\ReadableStreamInterface[]|\Icicle\Stream\WritableStreamInterface[]
     */
    abstract public function createStreams();

    public function testWrite()
    {
        list($readable, $writable) = $this->createStreams();

        $string = "{'New String\0To Write'}\r\n";

        $promise = new Coroutine($writable->write($string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($string));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $writable->close();

        $this->assertFalse($writable->isWritable());

        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteEmptyString()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($writable->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($writable->write('0'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(1));

        $promise->done($callback);

        $promise = new Coroutine($readable->read(1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo('0'));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testEnd()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($writable->end(StreamTest::WRITE_STRING));

        $this->assertFalse($writable->isWritable());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(StreamTest::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($writable->isOpen());
    }

    /**
     * @depends testWrite
     */
    public function testWriteTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING, StreamTest::TIMEOUT));
            Loop\tick(false);
        } while (!$promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testCloseAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $writable->close();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $buffer = '';

        for ($i = 0; $i < StreamTest::CHUNK_SIZE + 1; ++$i) {
            $buffer .= '1';
        }

        $promise = new Coroutine($writable->write($buffer));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($buffer)));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            new Coroutine($readable->read()); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testEnd
     * @depends testWriteAfterPendingWrite
     */
    public function testEndAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->end(StreamTest::WRITE_STRING));

        $this->assertFalse($writable->isWritable());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(StreamTest::WRITE_STRING)));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            new Coroutine($readable->read(StreamTest::CHUNK_SIZE)); // Pull more data out of the buffer.
            Loop\tick();
        }

        $this->assertFalse($writable->isWritable());
    }

    /**
     * @depends testWriteEmptyString
     * @depends testWriteAfterPendingWrite
     */
    public function testWriteEmptyStringAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            new Coroutine($readable->read()); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterPendingWriteAfterEof()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        // Extra write to ensure queue is not empty when write callback is called.
        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $readable->close(); // Close readable stream.

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteFailure()
    {
        list($readable, $writable) = $this->createStreams();

        // Use fclose() manually since $writable->close() will prevent behavior to be tested.
        fclose($writable->getResource());

        $promise = new Coroutine($writable->write(StreamTest::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(FailureException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }
}
