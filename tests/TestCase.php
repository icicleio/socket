<?php

/*
 * This file is part of the socket package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license Apache-2.0 See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Socket;

use Icicle\Tests\Socket\Stub\CallbackStub;

/**
 * Abstract test class with methods for creating callbacks.
 */
abstract class TestCase extends \PHPUnit_Framework_TestCase
{
    /**
     * Creates a callback that must be called $count times or the test will fail.
     *
     * @param int $count Number of times the callback should be called.
     *
     * @return callable|\PHPUnit_Framework_MockObject_MockObject Object that is callable and expects to be called the
     *     given number of times.
     */
    public function createCallback($count)
    {
        $mock = $this->getMock(CallbackStub::class);
        
        $mock->expects($this->exactly($count))
            ->method('__invoke');

        return $mock;
    }
}
