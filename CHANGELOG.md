# Changelog

### v0.1.1

- Changes
    - `Icicle\Socket\Stream\ReadableStreamTrait` now buffers bytes up to the max length given to `read()` (or 8kB if no length is given) to improve performance when reading to a particular byte.

---

### v0.1.0

- Initial release after split from the main [Icicle repository](https://github.com/icicleio/icicle).
