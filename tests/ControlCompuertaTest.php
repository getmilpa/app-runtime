<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests;

use PHPUnit\Framework\TestCase;

/** Deliberately red, to measure whether the branch gate refuses a merge. Never merged. */
final class ControlCompuertaTest extends TestCase
{
    public function testThisMustFail(): void
    {
        self::assertTrue(false, 'control positivo: esta prueba existe para estar roja');
    }
}
