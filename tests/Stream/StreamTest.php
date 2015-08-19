<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket\Stream;

use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Tests\Socket\TestCase;

abstract class StreamTest extends TestCase
{
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    
    /**
     * @return \Icicle\Stream\StreamInterface[]
     */
    abstract public function createStreams();

    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }
}