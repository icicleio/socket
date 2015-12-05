#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Socket\Server\Server;
use Icicle\Socket\Server\DefaultServerFactory;
use Icicle\Socket\Socket;

// Connect using `nc localhost 60000`.

$generator = function (Server $server) {
    $generator = function (Socket $socket) {
        try {
            yield from $socket->write("Want to play shadow? (Type 'exit' to quit)\n");
			
            while ($socket->isReadable()) {
                $data = yield from $socket->read();
                
                $data = trim($data, "\n");
                
                if ("exit" === $data) {
                    yield from $socket->end("Goodbye!\n");
                } else {
                    yield from $socket->write("Echo: {$data}\n");
                }
            }
        } catch (Exception $e) {
            echo "Client error: {$e->getMessage()}\n";
            $socket->close();
        }
    };
    
    echo "Echo server running on {$server->getAddress()}:{$server->getPort()}\n";
    
    while ($server->isOpen()) {
        $coroutine = new Coroutine(
            $generator(yield from $server->accept())
        );
    }
};

$coroutine = new Coroutine($generator(
    (new DefaultServerFactory())->create('127.0.0.1', 60000)
));

Loop\run();
