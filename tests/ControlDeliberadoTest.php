<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests;

use PHPUnit\Framework\TestCase;

/** A deliberate failure, to measure whether a merge tool can say no. Never merged. */
final class ControlDeliberadoTest extends TestCase
{
    public function testThisMustFail(): void
    {
        self::assertTrue(false, 'control positivo: esta prueba existe para estar roja');
    }
}
