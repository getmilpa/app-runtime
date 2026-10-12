<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\RunnerInput;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\SchemaCoercionException;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the terminal tells the house's judge a call carries is what its runner hands the operation (greenhouse
 * decisions/0615, «Lo que la tercera revisión encontró», M2; decided by Rod 2026-10-12).
 *
 * For an operation that declares no schema the runner hands on every flag it was typed with — `--sign` among them —
 * and the house told its judge that the call carried nothing. Held together HERE, against the runner itself: if
 * milpa/console changes how it derives, this fails, and the house's reading is changed with it.
 *
 * @subject-in milpa/app-runtime
 */
final class WhatTheTerminalTellsTheJudgeIsWhatItsRunnerHandsOnTest extends TestCase
{
    /** @return iterable<string, array{list<string>}> */
    public static function whatIsTyped(): iterable
    {
        yield 'nothing' => [[]];
        yield 'the signing flag' => [['--sign']];
        yield 'a stray flag' => [['--verbose']];
        yield 'a flag with a value, and the signing flag' => [['--scope=releases', '--sign']];
        yield 'a value with an equals sign in it' => [['--filter=a=b']];
        yield 'a flag said twice: the last one' => [['--scope=one', '--scope=two']];
        yield 'what does not start with two dashes' => [['releases', '-v', '--scope=releases']];
        yield 'a flag with an empty value' => [['--scope=']];
    }

    /** @param list<string> $tokens */
    #[DataProvider('whatIsTyped')]
    public function testForAnOperationWithNoSchemaItIsTheBagTheRunnerHandsOn(array $tokens): void
    {
        $handed = null;
        $bare = new Operation('lab:watch', 'Lab: a watcher that declares no schema', static function (array $input) use (&$handed): array {
            $handed = $input;

            return ['ok' => true, 'fired' => false];
        }, scopes: [], effects: EffectProfile::readOnly());
        $runner = new CliRunner();

        $told = RunnerInput::of($bare, $tokens);

        // The runner's own derivation for such an operation — the private one `run()` calls, `--sign` included.
        self::assertSame((new \ReflectionMethod($runner, 'rawBag'))->invoke($runner, $tokens), $told);
        // …and, for a call nobody has to sign, what the handler is in fact handed by a run.
        if (!\in_array('--sign', $tokens, true)) {
            self::assertSame(0, $runner->run($bare, $tokens, new DIContainer(), static function (string $line): void {
            }));
            self::assertSame($handed, $told);
        }
    }

    public function testTheSigningFlagIsAnArgumentOfSuchACallAndTheHouseSaysSo(): void
    {
        $bare = new Operation('lab:watch', 'Lab', static fn (array $input): array => ['ok' => true], scopes: [], effects: EffectProfile::readOnly());

        self::assertSame(['sign' => '1'], RunnerInput::of($bare, ['--sign']), 'the house told its judge that a signed run of a watcher carried nothing');
    }

    public function testForAnOperationWithASchemaItIsWhatTheRunnerDerivesAndTheSigningFlagIsNotInput(): void
    {
        $typed = new Operation('lab:scan', 'Lab', static fn (array $input): array => ['ok' => true], ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'deep' => ['type' => 'boolean']], 'required' => ['path']], scopes: [], effects: EffectProfile::readOnly());
        $tokens = ['--path=good.txt', '--deep', '--sign', '--stray=1'];

        self::assertSame((new CliRunner())->deriveInput($typed, $tokens), RunnerInput::of($typed, $tokens));
        self::assertSame(['path' => 'good.txt', 'deep' => true], RunnerInput::of($typed, $tokens));
        $this->expectException(SchemaCoercionException::class);
        RunnerInput::of($typed, ['--sign']);
    }
}
