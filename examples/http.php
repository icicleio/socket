#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Socket\Server\Server;
use Icicle\Socket\Server\DefaultServerFactory;
use Icicle\Socket\Socket;

$server = (new DefaultServerFactory())->create('127.0.0.1', 8080, ['backlog' => 1024]);

$generator = function (Server $server) {
    $generator = function (Socket $socket) {
        try {
            $data = yield $socket->read();
            
            $microtime = sprintf("%0.4f", microtime(true));
            $message = "Received the following request ({$microtime}):\r\n\r\n{$data}";
            $length = strlen($message);
            
            $data  = "HTTP/1.1 200 OK\r\n";
            $data .= "Content-Type: text/plain\r\n";
            $data .= "Content-Length: {$length}\r\n";
            $data .= "Connection: close\r\n";
            $data .= "\r\n";
            $data .= $message;
            
            yield $socket->write($data);
        } finally {
            $socket->close();
        }
    };
    
    while ($server->isOpen()) {
        $coroutine = new Coroutine(
            $generator(yield $server->accept())
        );
    }
};

$coroutine = new Coroutine($generator($server));

$coroutine->cleanup(function () use ($server) {
    $server->close();
});

echo "Server started.\n";

Loop\run();
