# Changelog

## [0.5.3] - 2015-12-29
### Added
- `Icicle\Socket\NetworkSocket`, `Icicle\Socket\Server\BasicServer`, and `Icicle\Socket\Datagram\BasicDatagram` will automatically free resources in the event loop associated with the server/datagram and call `fclose()` on the stream resource when the object is destructed. This means `close()` does not need to be called on the object to avoid memory leaks in the loop or close the resource. The constructors of these classes have an additional boolean parameter `$autoClose` that defaults to `true`, but can be set to `false` to avoid automatically calling `fclose()` on the resource.

## [0.5.2] - 2015-12-21
### Added
- Added an `unshift()` method to `Icicle\Socket\Socket` that shifts data back to the front of the stream. The data will be the first data read from any pending on subsequent read.

## [0.5.1] - 2015-12-20
### Changed
- Simultaneous reads are now allowed on `Icicle\Socket\Datagram\BasicDatagram` and simultaneous accepts are allowed on `Icicle\Socket\Server\BasicServer`, fulfilling in the order they were created. Simultaneous reads/accepts will not fulfill with the same data, rather each is fulfilled independently with new data read from the stream or new client accepted on the server.

## [0.5.0] - 2015-12-04
### Changes
- All interface names have been changed to remove the `Interface` suffix. Since most classes in this package would now conflict with the interface names, the classes are prefixed with either `Basic` or `Default` (e.g.: `Icicle\Socket\Server\BasicServer` and `Icicle\Socket\Connector\DefaultConnector`).

### Added
- Improved enabling crypto on `Icicle\Socket\NetworkSocket` to force the highest TLS version supported by the client and allowed by the `$method` parameter to be selected by the server.

## [0.4.1] - 2015-11-02
### Added
- Added a `rebind()` method to `Icicle\Socket\Server\Server` and `Icicle\Socket\Datagram\Datagram` that rebinds the object to the current event loop instance. This method should be used if the event loop is switched out during runtime (for example, when forking using the concurrent package).

## [0.4.0] - 2015-10-16
### New Features
- Added functions `Icicle\Socket\connector()` and `Icicle\Socket\connect()`. These functions are used to access and set a global connector object and use that object to connect to remote servers.

### Changes
- Moved stream socket classes to the `icicleio/stream` package.
- Renamed `Icicle\Socket\Client\Client` and `Icicle\Socket\Client\ClientInterface` to `Icicle\Socket\Socket` and `Icicle\Socket\SocketInterface`. These names better represent the purpose of the class and interface (as they are not strictly for client connections, but remote sockets in general).
- `Icicle\Socket\Server\ServerInterface::accept()` now resolves to an instance of `Icicle\Socket\SocketInterface`.

## [0.3.1] - 2015-09-04
### Fixed
- Fixed typo in line that prevents functions from being defined twice.

## [0.3.0] - 2015-09-04
### Added
- Added some socket utility functions in the `Icicle\Socket` namespace. Notably `Icicle\Socket\pair()` that returns a pair of connected stream sockets.
    
### Changed
- Objects no longer bind to the event loop until needed. This will allow a server to be created or a client to be accepted, then sent to a thread with a separate event loop.
- The byte parameter must be a single-byte string. Use `chr()` to convert an integer to a single ascii character (change made in `icicleio/stream` v0.3.0).

## [0.2.1] - 2015-08-24
### Changed
- `fclose()` is no longer automatically called by `Socket::__destruct()`, `Socket::close()` must be called to invoke `fclose()` on the socket.

## [0.2.0]
### Changed
- Stream methods previously returning promises are now coroutines, matching the changes made to stream interfaces in `icicleio/stream` v0.2.0.
- `Icicle\Socket\Server\ServerInterface::accept()` is now a coroutine, as well as the `receive()` and `send()` methods of `Icicle\Socket\Datagram\DatagramInterface`.

### Fixed
- Fixed an issue where the internal socket event object was not freed if the coroutine `Icicle\Socket\Client\Connector::connect()` was cancelled.

## [0.1.1]
### Changed
- `Icicle\Socket\Stream\ReadableStreamTrait` now buffers bytes up to the max length given to `read()` (or 8kB if no length is given) to improve performance when reading to a particular byte.

## [0.1.0]
- Initial release after split from the main [Icicle repository](https://github.com/icicleio/icicle).


[0.5.3]: https://github.com/icicleio/socket/releases/tag/v0.5.3
[0.5.2]: https://github.com/icicleio/socket/releases/tag/v0.5.2
[0.5.1]: https://github.com/icicleio/socket/releases/tag/v0.5.1
[0.5.0]: https://github.com/icicleio/socket/releases/tag/v0.5.0
[0.4.1]: https://github.com/icicleio/socket/releases/tag/v0.4.1
[0.4.0]: https://github.com/icicleio/socket/releases/tag/v0.4.0
[0.3.1]: https://github.com/icicleio/socket/releases/tag/v0.3.1
[0.3.0]: https://github.com/icicleio/socket/releases/tag/v0.3.0
[0.2.1]: https://github.com/icicleio/socket/releases/tag/v0.2.1
[0.2.0]: https://github.com/icicleio/socket/releases/tag/v0.2.0
[0.1.1]: https://github.com/icicleio/socket/releases/tag/v0.1.1
[0.1.0]: https://github.com/icicleio/socket/releases/tag/v0.1.0
