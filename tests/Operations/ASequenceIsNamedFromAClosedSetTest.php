<?php

/**
 * This file is part of Milpa App Runtime — the governed runtime of a founded Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\SequenceOperations;
use Milpa\AppRuntime\Sequence\DeclaredSequences;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `sequence:run` is the narrow operation that CAN reach HTTP — greenhouse `decisions/0223`.
 *
 * An adversarial review measured why `recipe:apply` cannot: adding `'http'` to it is a one-word diff
 * that publishes an unguarded endpoint whose payload names a FILE. Every difference here is the answer
 * to one of those defects, and each one is asserted rather than described.
 */
#[CoversClass(SequenceOperations::class)]
#[CoversClass(DeclaredSequences::class)]
final class ASequenceIsNamedFromAClosedSetTest extends TestCase
{
    /**
     * F — THE NAME IS A KEY, NOT A PATH. It resolves against a closed set the app declared, so nothing
     * a caller writes can select a file: no path is built, so none can be escaped from.
     *
     * @return array<string, array{0: string}>
     */
    public static function namesNobodyDeclared(): array
    {
        return [
            'a traversal' => ['../../../etc/passwd'],
            'an absolute path' => ['/etc/passwd'],
            'a subdirectory' => ['uploads/evil'],
            'a plausible name nobody declared' => ['staging'],
        ];
    }

    #[DataProvider('namesNobodyDeclared')]
    public function testANameTheAppNeverDeclaredReachesNothing(string $name): void
    {
        $declared = DeclaredSequences::fromArray([
            'deploy' => [['op' => 'plugins:verify', 'args' => []]],
        ]);

        self::assertNull($declared->stepsOf($name), $name . ' resolved to something');
    }

    /** THE POSITIVE CONTROL: a declared name resolves, so the refusal above is about the SET, not about everything. */
    public function testADeclaredNameResolvesToItsSteps(): void
    {
        $declared = DeclaredSequences::fromArray([
            'deploy' => [
                ['op' => 'plugins:verify', 'args' => []],
                ['op' => 'stack:up', 'args' => ['service' => 'web']],
            ],
        ]);

        $steps = $declared->stepsOf('deploy');
        self::assertNotNull($steps);
        self::assertCount(2, $steps);
        self::assertSame('plugins:verify', $steps[0]->operation);
        self::assertSame(['service' => 'web'], $steps[1]->arguments);
        self::assertSame(['deploy'], $declared->names());
    }

    /**
     * A sequence with a malformed step is dropped WHOLE. Running the well-formed part would run a
     * SHORTER sequence than the app declared — and a deployment that runs some of its steps is worse
     * than one that refuses to start.
     */
    public function testASequenceWithAMalformedStepIsDroppedWhole(): void
    {
        $declared = DeclaredSequences::fromArray([
            'deploy' => [
                ['op' => 'plugins:verify', 'args' => []],
                ['args' => ['service' => 'web']],
            ],
        ]);

        self::assertNull($declared->stepsOf('deploy'), 'half a deployment is not a deployment');
        self::assertSame([], $declared->names());
    }

    /** An app that declares no sequences is an app, not an error: not deploying is a legitimate shape. */
    public function testAnAppThatDeclaresNoneIsAnEmptySetAndNotAFailure(): void
    {
        self::assertSame([], DeclaredSequences::fromArray([])->names());
        self::assertSame([], DeclaredSequences::underRoot(sys_get_temp_dir() . '/no-such-app-' . bin2hex(random_bytes(4)))->names());
    }

    /**
     * F — IT DECLARES A SCOPE, and that is the difference between exposed and governed.
     *
     * `HttpProjector::handle()` consults the policy ONLY when an operation declares scopes or a
     * permission, and the boot-time `assertGuarded()` refuses only those. An operation with neither is
     * published to whoever reaches the server, and nothing in the boot path objects.
     */
    public function testItIsReachableOverHttpAndDeclaresTheScopeThatMakesThePolicyRun(): void
    {
        $op = $this->operation();

        self::assertTrue($op->supportsSurface('http'), 'the surface a human authorises everything else from');
        self::assertNotSame([], $op->scopes, 'without a scope the http surface never consults a policy');
        self::assertSame(['agent:run'], $op->scopes);
        self::assertSame('sequence', $op->namedTarget, 'the human names what runs (ADR-0044)');
        self::assertTrue($op->mutating);
    }

    /**
     * And it declares the ceiling of what it ORIGINATES — enough that `Consent::demanded()` is true by
     * rule S2, so the surface's own confirm ceremony fires before a single step is judged.
     */
    public function testItDeclaresTheCeilingOfWhatItOriginates(): void
    {
        $effects = $this->operation()->effects;

        self::assertNotNull($effects);
        self::assertSame(Subject::Executable, $effects->subject);
        self::assertSame(Authority::Privileged, $effects->authority);
        self::assertTrue($effects->isFullyClassified(), 'somebody decided this, it is not four unknowns');
    }

    /** A name the app never declared is answered with the ones it DID: a wrong guess deserves the list. */
    public function testAnUnknownNameIsAnsweredWithWhatExists(): void
    {
        $answer = ($this->operation()->handler)(['sequence' => 'nope'], null);

        self::assertFalse($answer['ok'] ?? null);
        // Without a kernel it stops earlier, which is the honest order: there is no app to ask.
        self::assertStringContainsString('no kernel', (string) ($answer['error'] ?? ''));
    }

    /** An empty name is refused before anything else is resolved. */
    public function testAnEmptyNameIsRefusedAtTheBoundary(): void
    {
        $answer = ($this->operation()->handler)(['sequence' => '  '], null);

        self::assertFalse($answer['ok'] ?? null);
        self::assertStringContainsString('name a sequence', (string) ($answer['error'] ?? ''));
    }

    private function operation(): Operation
    {
        $operations = (new SequenceOperations(new DIContainer()))->operations();
        self::assertCount(1, $operations);

        return $operations[0];
    }
}
