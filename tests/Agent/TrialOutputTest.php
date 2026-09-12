<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Child behavior defines the expected bytes; a pipe buffer cannot define the result. */
final class TrialOutputTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, int}> */
    public static function outputCases(): iterable
    {
        $out = str_repeat('o', 131072);
        $err = str_repeat('e', 131072);
        yield 'empty success' => ['exit(0);', '', '', 0];
        yield 'large stdout' => ['fwrite(STDOUT, str_repeat("o", 131072));', $out, '', 0];
        yield 'large stderr then JSON' => ['fwrite(STDERR, str_repeat("e", 131072)); echo "{\"ok\":true}\\n";', "{\"ok\":true}\n", $err, 0];
        yield 'alternating large channels' => ['for($i=0;$i<16;$i++){fwrite(STDERR,str_repeat("e",8192));fwrite(STDOUT,str_repeat("o",8192));}', $out, $err, 0];
        yield 'stdout closes first' => ['fclose(STDOUT); fwrite(STDERR,str_repeat("e",131072));', '', $err, 0];
        yield 'stderr closes first' => ['fclose(STDERR); fwrite(STDOUT,str_repeat("o",131072));', $out, '', 0];
        yield 'nonzero exit retained' => ['fwrite(STDERR,str_repeat("e",131072)); echo "{\"ok\":false}\\n"; exit(7);', "{\"ok\":false}\n", $err, 7];
        yield 'timeout retains partial output' => ['fwrite(STDERR,str_repeat("e",131072));fwrite(STDOUT,str_repeat("o",131072));sleep(10);', $out, $err, 124];
        yield 'both channels close before process exits' => ['fclose(STDOUT);fclose(STDERR);sleep(10);', '', '', 124];
    }

    #[DataProvider('outputCases')]
    public function testBothChannelsSurviveAndTheProcessKeepsItsExit(string $program, string $stdout, string $stderr, int $exit): void
    {
        $command = 'timeout -k 1 2 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($program);
        $actual = (new \ReflectionMethod(TrialRunner::class, 'exec'))->invoke(new TrialRunner(), $command);
        self::assertSame($exit, $actual[0]);
        self::assertSame(strlen($stdout), strlen($actual[1]));
        self::assertSame(hash('sha256', $stdout), hash('sha256', $actual[1]));
        self::assertSame(strlen($stderr), strlen($actual[2]));
        self::assertSame(hash('sha256', $stderr), hash('sha256', $actual[2]));
    }
}
