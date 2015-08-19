# Changelog

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
