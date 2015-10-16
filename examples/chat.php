#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine;
use Icicle\Loop;
use Icicle\Socket\Server\ServerInterface;
use Icicle\Socket\Server\ServerFactory;
use Icicle\Socket\SocketInterface;

// Connect using `nc localhost 60000`.

$coroutine = Coroutine\create(function (ServerInterface $server) {
    $sockets = new SplObjectStorage();
    
    $handler = Coroutine\wrap(function (SocketInterface $socket) use (&$sockets) {
        $sockets->attach($socket);
        $name = $socket->getRemoteAddress() . ':' . $socket->getRemotePort();

        try {
            foreach ($sockets as $stream) {
                if ($socket !== $stream) {
                    yield $stream->write("{$name} connected.\n");
                }
            }

            yield $socket->write("Welcome {$name}!\n");
            
            while ($socket->isReadable()) {
                $data = trim(yield $socket->read());
                
                if ("/exit" === $data) {
                    yield $socket->end("Goodbye!\n");
                } elseif ('' !== $data) {
                    $message = "{$name}: {$data}\n";
                    foreach ($sockets as $stream) {
                        if ($socket !== $stream) {
                            yield $stream->write($message);
                        }
                    }
                }
            }
        } catch (Exception $exception) {
            $socket->close();
        }

        $sockets->detach($socket);
        foreach ($sockets as $stream) {
            yield $stream->write("{$name} disconnected.\n");
        }
    });
    
    while ($server->isOpen()) {
        $handler(yield $server->accept());
    }
}, (new ServerFactory())->create('127.0.0.1', 60000));

$coroutine->done();

Loop\run();

