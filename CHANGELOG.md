# Changelog

### v0.4.0

- New Features
    - Added functions `Icicle\Socket\connector()` and `Icicle\Socket\connect()`. These functions are used to access and set a global connector object and use that object to connect to remote servers.

- Changes
    - Moved stream socket classes to the `icicleio/stream` package.
    - Renamed `Icicle\Socket\Client\Client` and `Icicle\Socket\Client\ClientInterface` to `Icicle\Socket\Socket` and `Icicle\Socket\SocketInterface`. These names better represent the purpose of the class and interface (as they are not strictly for client connections, but remote sockets in general).
    - `Icicle\Socket\Server\ServerInterface::accept()` now resolves to an instance of `Icicle\Socket\SocketInterface`.
    
---

### v0.3.1

- Bug Fixes
    - Fixed typo in line that prevents functions from being defined twice.

---

### v0.3.0

- New Features
    - Added some socket utility functions in the `Icicle\Socket` namespace. Notably `Icicle\Socket\pair()` that returns a pair of connected stream sockets.
    
- Changes
    - Objects no longer bind to the event loop until needed. This will allow a server to be created or a client to be accepted, then sent to a thread with a separate event loop.
    - The byte parameter must be a single-byte string. Use `chr()` to convert an integer to a single ascii character (change made in `icicleio/stream` v0.3.0).

---

### v0.2.1

- Changes
    - `fclose()` is no longer automatically called by `Socket::__destruct()`, `Socket::close()` must be called to invoke `fclose()` on the socket.

---

### v0.2.0

- Changes
    - Stream methods previously returning promises are now coroutines, matching the changes made to stream interfaces in `icicleio/stream` v0.2.0.
    - `Icicle\Socket\Server\ServerInterface::accept()` is now a coroutine, as well as the `receive()` and `send()` methods of `Icicle\Socket\Datagram\DatagramInterface`.

- Bug Fixes
    - Fixed an issue where the internal socket event object was not freed if the coroutine `Icicle\Socket\Client\Connector::connect()` was cancelled.

---

### v0.1.1

- Changes
    - `Icicle\Socket\Stream\ReadableStreamTrait` now buffers bytes up to the max length given to `read()` (or 8kB if no length is given) to improve performance when reading to a particular byte.

---

### v0.1.0

- Initial release after split from the main [Icicle repository](https://github.com/icicleio/icicle).
