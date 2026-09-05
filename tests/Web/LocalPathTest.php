<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\LocalPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A `next` target is a local absolute path or `/` — never a URL somebody else chose (decisions/0206). */
final class LocalPathTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function candidates(): iterable
    {
        yield 'a local path' => ['/milpa/admin', '/milpa/admin'];
        yield 'a local path with a query' => ['/milpa/admin?tab=routes&x=1', '/milpa/admin?tab=routes&x=1'];
        yield 'the root' => ['/', '/'];
        yield 'protocol-relative' => ['//evil.example', '/'];
        yield 'an absolute URL' => ['https://evil.example/', '/'];
        yield 'a backslash trick' => ['/\\evil.example', '/'];
        yield 'a bare backslash' => ['\\x', '/'];
        yield 'a relative path' => ['milpa/admin', '/'];
        yield 'a scheme without slashes' => ['javascript:alert(1)', '/'];
        yield 'a newline' => ["/milpa\nLocation: x", '/'];
        yield 'a space' => ['/milpa admin', '/'];
        yield 'empty' => ['', '/'];
        yield 'null' => [null, '/'];
        yield 'not a string' => [['/x'], '/'];
    }

    #[DataProvider('candidates')]
    public function testOnlyALocalAbsolutePathSurvives(mixed $candidate, string $expected): void
    {
        self::assertSame($expected, LocalPath::orRoot($candidate));
    }
}
