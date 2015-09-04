# Changelog

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
