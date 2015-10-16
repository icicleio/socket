# Asynchronous Sockets for Icicle

This library is a component for [Icicle](https://github.com/icicleio/icicle), providing an asynchronous stream socket server, connector, and datagram. Like other Icicle components, this library uses [Promises](https://github.com/icicleio/icicle/wiki/Promises) and [Generators](http://www.php.net/manual/en/language.generators.overview.php) for asynchronous operations that may be used to build [Coroutines](https://github.com/icicleio/icicle/wiki/Coroutines) to make writing asynchronous code more like writing synchronous code.

[![Build Status](https://img.shields.io/travis/icicleio/socket/v1.x.svg?style=flat-square)](https://travis-ci.org/icicleio/socket)
[![Coverage Status](https://img.shields.io/coveralls/icicleio/socket/v1.x.svg?style=flat-square)](https://coveralls.io/r/icicleio/socket)
[![Semantic Version](https://img.shields.io/github/release/icicleio/socket.svg?style=flat-square)](http://semver.org)
[![MIT License](https://img.shields.io/packagist/l/icicleio/socket.svg?style=flat-square)](LICENSE)
[![@icicleio on Twitter](https://img.shields.io/badge/twitter-%40icicleio-5189c7.svg?style=flat-square)](https://twitter.com/icicleio)

##### Requirements

- PHP 5.5+

##### Suggested

- [openssl extension](http://php.net/manual/en/book.openssl.php): Enables using SSL/TLS on sockets.

##### Installation

The recommended way to install is with the [Composer](http://getcomposer.org/) package manager. (See the [Composer installation guide](https://getcomposer.org/doc/00-intro.md) for information on installing and using Composer.)

Run the following command to use this library in your project: 

```bash
composer require icicleio/socket
```

You can also manually edit `composer.json` to add this library as a project requirement.

```js
// composer.json
{
    "require": {
        "icicleio/socket": "^0.4"
    }
}
```

The socket component implements network sockets as coroutine-based streams, server, and datagram. Creating a server and accepting connections is very simple, requiring only a few lines of code.

The example below implements a simple HTTP server listening on 127.0.0.1:8080 that responds to any request with the contents of the client request as the body of the response. This example is implemented using coroutines (see the [Coroutine API documentation](https://github.com/icicleio/icicle/wiki/Coroutines)) and the basic sockets provided by this package.

```php
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Socket\Socket;
use Icicle\Socket\SocketInterface;
use Icicle\Socket\Server\Server;
use Icicle\Socket\Server\ServerInterface;
use Icicle\Socket\Server\ServerFactory;

$server = (new ServerFactory())->create('localhost', 8080);

$generator = function (ServerInterface $server) {
    printf("Server listening on %s:%d\n", $server->getAddress(), $server->getPort());

    $generator = function (SocketInterface $socket) {
        $request = '';
        do {
            $request .= (yield $socket->read(0, "\n"));
        } while (substr($request, -4) !== "\r\n\r\n");

        $message = sprintf("Received the following request:\r\n\r\n%s", $request);

        $data  = "HTTP/1.1 200 OK\r\n";
        $data .= "Content-Type: text/plain\r\n";
        $data .= sprintf("Content-Length: %d\r\n", strlen($message));
        $data .= "Connection: close\r\n";
        $data .= "\r\n";
        $data .= $message;

        yield $client->write($data);

        $client->close();
    };

    while ($server->isOpen()) {
        // Handle client in a separate coroutine so this coroutine is not blocked.
        $coroutine = new Coroutine($generator(yield $server->accept()));
        $coroutine->done(null, function (Exception $exception) {
            printf("Client error: %s\n", $exception->getMessage());
        });
    }
};

$coroutine = new Coroutine($generator($server));
$coroutine->done();

Loop\run();
```

## Documentation

- [Server](#server)
    - [Server Constructor](#server-constructor) - Creates a server from a stream socket server resource.
    - [accept()](#accept) - A coroutine that is resolved when a client connects.
    - [getAddress()](#getaddress) - Returns the address of the server.
    - [getPort()](#getport) - Returns the port of the server.
- [ServerFactory](#serverfactory)
    - [create()](#create) - Creates a `Server` on a given host and port.
- [Socket](#socket)
    - [Socket Constructor](#socket-constructor) - Creates a socket object from a stream socket resource.
    - [enableCrypto()](#enablecrypto) - Enables crypto on the socket.
    - [getLocalAddress()](#getlocaladdress) - Returns the local address of the socket.
    - [getLocalPort()](#getlocalport) - Returns the local port of the socket.
    - [getRemoteAddress()](#getremoteaddress) - Returns the remote address of the socket.
    - [getRemotePort()](#getremoteport) - Returns the remote port of the socket.
- [Connector](#connector)
    - [connect()](#connect) - A coroutine resolved with a `Socket` object when a connection is established.
- [Datagram](#datagram) - UDP socket listener
    - [Datagram Constructor](#datagram-constructor)
    - [receive()](#receive) - Receives data from the datagram.
    - [send()](#send) - Sends data to a address and port.
    - [getAddress()](#getaddress1) - Returns the address of the datagram.
    - [getPort()](#getport1) - Returns the port of the datagram.
- [DatagramFactory](#datagramfactory)
    - [create()](#create1) - Creates a `Datagram` on a given host and port.
- [Functions](#functions)
    - [connect()](#socketconnect) - Uses the global connector to connect to the given IP and port.
    - [connector()](#socketconnector) - Accesses or sets the global connector instance.

#### Function prototypes

Prototypes for object instance methods are described below using the following syntax:

```php
ClassOrInterfaceName::methodName(ArgumentType $arg): ReturnType
```

## Server

The `Icicle\Socket\Server\Server` class implements `Icicle\Socket\Server\ServerInterface`, a coroutine-based interface for creating a TCP server and accepting connections.

#### Server Constructor

```php
$server = new Server(resource $socket)
```

Creates a server from a stream socket server resource generated from `stream_socket_server()`. Generally it is better to use `Icicle\Socket\Server\ServerFactory` to create a `Icicle\Socket\Server\Server` instance.

---

#### accept()

```php
ServerInterface::accept(): Generator
```

A coroutine that is resolved with a `Icicle\Socket\SocketInterface` object when a connection is accepted.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `Icicle\Socket\SocketInterface` | Accepted client connection.
Rejected | `Icicle\Socket\Exception\BusyException` | If the server already had an accept pending.
Rejected | `Icicle\Socket\Exception\UnavailableException` | If the server was previously closed.
Rejected | `Icicle\Socket\Exception\ClosedException` | If the server is closed during pending accept.

---

#### getAddress()

```php
ServerInterface::getAddress(): string
```

Returns the local IP address as a string.

---

#### getPort()

```php
ServerInterface::getPort(): int
```

Returns the local port.

## ServerFactory

`Icicle\Socket\Server\ServerFactory` (implements `Icicle\Socket\Server\ServerFactoryInterface`) can be used to create server instances from a IP or unix socket path, port number (`null` for unix socket), and list of options.

#### create()

```php
ServerFactoryInterface::create(
    string $host,
    int $port,
    mixed[] $options = []
): ServerInterface
```

Creates a server bound and listening on the given ip or unix socket path and port number (`null` for unix socket).

Option | Type | Description
:-- | :-- | :--
`backlog` | `int` | Connection backlog size. Note that the operating system variable `SOMAXCONN` may set an upper limit and may need to be changed to allow a larger backlog size.
`pem` | `string` | Path to PEM file containing certificate and private key to enable SSL on client connections.
`passphrase` | `string` | PEM passphrase if applicable.
`name` | `string` | Name to use as SNI identifier. If not set, name will be guessed based on `$host`.

## Socket

`Icicle\Socket\Socket` objects implement `Icicle\Socket\SocketInterface` and are used as the fulfillment value of the coroutine returned by `Icicle\Socket\Server\Server::accept()` ([see documentation above](#accept)). (Note that `Icicle\Socket\Server\Server` can be easily extended and modified to fulfill accept requests with different objects implementing `Icicle\Socket\SocketInterface`.)

The class extends `Icicle\Stream\Pipe\DuplexPipe`, so it inherits all the readable and writable stream methods as well as adding those below.

#### Socket Constructor

```php
$socket = new Socket(resource $socket)
```

Creates a socket object from the given stream socket resource.

---

#### enableCrypto()

```php
SocketInterface::enableCrypto(int $method, float $timeout = 0): Generator
```

Enables encryption on the socket. For Socket objects created from `Icicle\Socket\Server\Server::accept()`, a PEM file must have been provided when creating the server socket (see `Icicle\Socket\Server\ServerFactory`). Use the `STREAM_CRYPTO_METHOD_*_SERVER` constants when enabling crypto on remote clients (e.g., created by `Icicle\Socket\Server\ServerInterface::accept()`) and the `STREAM_CRYPTO_METHOD_*_CLIENT` constants when enabling crypto on a local client connection (e.g., created by `Icicle\Socket\Connector\ConnectorInterface::connect()`).

---

#### getLocalAddress()

```php
SocketInterface::getLocalAddress(): string
```

Returns the local IP address as a string.

---

#### getLocalPort()

```php
SocketInterface::getLocalPort(): int
```

Returns the local port.

---

#### getRemoteAddress()

```php
SocketInterface::getRemoteAddress(): string
```

Returns the remote IP address as a string.

---

#### getRemotePort()

```php
SocketInterface::getRemotePort(): int
```

Returns the remote port.

## Connector

The `Icicle\Socket\Connector\Connector` class (implements `Icicle\Socket\Connector\ConnectorInterface`) asynchronously connects to a remote server, returning a coroutine that is fulfilled with an instance of `Icicle\Socket\SocketInterface` when the connection is successfully established. Note that the *host should be given as an IP address*, as DNS lookups performed by PHP are synchronous (blocking). If you wish to use domain names instead of IPs, see `Icicle\Dns\Connector\Connector` in the [DNS component](https://github.com/icicleio/dns).

#### connect()

```php
ConnectorInterface::connect(
    string $host,
    int|null $port,
    mixed[] $options = []
): Generator
```

Connects asynchronously to the given IP or unix socket path on the given port number (`null` for unix socket).

Option | Type | Description
:-- | :-- | :--
`protocol` | `string` | The protocol to use, such as tcp, udp, s3, ssh. Defaults to tcp.
`timeout` | `float` | Number of seconds until connection attempt times out. Defaults to 10 seconds.
`cn` | `string` | Host name (common name) used to verify certificate. e.g., `*.google.com`
`allow_self_signed` | `bool` | Set to `true` to allow self-signed certificates. Defaults to `false`.
`max_depth` | `int` | Max levels of certificate authorities the verifier will transverse. Defaults to 10.
`cafile` | `string` | Path to bundle of root certificates to verify against.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `Icicle\Socket\SocketInterface` | Fulfilled once the connection is established.
Rejected | `Icicle\Socket\Exception\FailureException` | If the connection attempt fails (such as an invalid host).
Rejected | `Icicle\Promise\Exception\TimeoutException` | If the connection attempt times out.

## Datagram

The `Icicle\Socket\Datagram\Datagram` class implements `Icicle\Socket\Datagram\DatagramInterface`, a coroutine-based interface for creating a UDP listener and sender.

#### Datagram Constructor

```php
$datagram = new Datagram(resource $socket)
```

Creates a datagram from a stream socket server resource generated from `stream_socket_server()`. Generally it is better to use `Icicle\Socket\Datagram\DatagramFactory` to create a `Icicle\Socket\Datagram\Datagram` instance.

---

#### receive()

```php
DatagramInterface::receive(int $length, float $timeout): Generator
```

A coroutine that is fulfilled with an array when a data is received on the UDP socket (datagram). The array is a 0-indexed array containing the IP address, port, and data received, in that order.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `array` | IP address, port, and data received.
Rejected | `Icicle\Socket\Exception\BusyException` | If the server already had an accept pending.
Rejected | `Icicle\Stream\Exception\UnavailableException` | If the server was previously closed.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the server is closed during pending accept.

---

#### send()

```php
DatagramInterface::send(
    string $address,
    int $port,
    string $data
): Generator
```

Send the given data to the IP address and port. This coroutine is fulfilled with the amount of data sent once the data has successfully been sent.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `int` | Length of data sent.
Rejected | `Icicle\Socket\Exception\BusyException` | If the server already had an accept pending.
Rejected | `Icicle\Stream\Exception\UnavailableException` | If the server was previously closed.
Rejected | `Icicle\Stream\Exception\ClosedException` | If the server is closed during pending accept.

---

#### getAddress()

```php
DatagramInterface::getAddress(): string
```

Returns the local IP address as a string.

---

#### getPort()

```php
DatagramInterface::getPort(): int
```

Returns the local port.

## DatagramFactory

`Icicle\Socket\Datagram\DatagramFactory` (implements `Icicle\Socket\Datagram\DatagramFactoryInterface`) can be used to create datagram instances from a hostname or unix socket path, port number (`null` for unix socket), and list of options.

#### create()

```php
DatagramFactoryInterface::create(
    string $host,
    int $port,
    mixed[] $options = []
): DatagramInterface
```

Creates a datagram bound and listening on the given IP and port number. No options are defined in this implementation.

## Functions

#### Socket\connect()

```php
Icicle\Socket\connect(
    string $ip,
    int|null $port,
    array $options = []
): Generator
```

Connects asynchronously to the given host on the given port. Uses the global connector interface that can be set using `Icicle\Socket\connector()`.

Option | Type | Description
:-- | :-- | :--
`protocol` | `string` | The protocol to use, such as tcp, udp, s3, ssh. Defaults to tcp.
`timeout` | `float` | Number of seconds until connection attempt times out. Defaults to 10 seconds.
`cn` | `string` | Host name (common name) used to verify certificate. e.g., `*.google.com`
`allow_self_signed` | `bool` | Set to `true` to allow self-signed certificates. Defaults to `false`.
`max_depth` | `int` | Max levels of certificate authorities the verifier will transverse. Defaults to 10.
`cafile` | `string` | Path to bundle of root certificates to verify against.

Resolution | Type | Description
:-: | :-- | :--
Fulfilled | `Icicle\Socket\SocketInterface` | Fulfilled once the connection is established.
Rejected | `Icicle\Socket\Exception\FailureException` | If the connection attempt fails (such as an invalid host).
Rejected | `Icicle\Promise\Exception\TimeoutException` | If the connection attempt times out.

---

#### Socket\connector()

```php
Icicle\Socket\connector(
    ConnectorInterface|null $connector = null
): ConnectorInterface
```

Gets the global connector instance. If a connector instance is provided, that instance is set as the global connector instance.
