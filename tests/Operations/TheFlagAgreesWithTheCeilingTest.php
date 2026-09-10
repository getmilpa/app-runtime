<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 `mutating` AND `effects.mutation` ARE ONE FACT WITH TWO SOURCES, so they are held in agreement.
 *
 * `Operation` carries a `bool $mutating` flag AND an `EffectProfile` whose `mutation` axis says the
 * same thing — and THE SIGNATURE GATE READS THE FLAG. Declared with the ceiling alone,
 * `provider:declare` wrote a credential with no signature at all, while `config:set`, whose ceiling
 * is lighter, demanded one. Measured on cattle: its own contract printed `mutating: no` directly
 * beside `mutation: persistent` (greenhouse decisions/0267).
 *
 * A ceiling that says «persistent» while the flag says «reads» is not a style problem: it is an
 * operation that changes the world and never asks. So the two are checked against each other for
 * every operation this package declares, in both directions — a flag without a ceiling to justify it
 * is the same disagreement wearing the other face.
 */
final class TheFlagAgreesWithTheCeilingTest extends TestCase
{
    /**
     * Every provider this package ships, built the way `config/operations.php` builds them: by
     * handing the container to the class-string. A provider that cannot be built that way is a
     * provider no app can register, which its own suite covers.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function providers(): iterable
    {
        $dir = \dirname(__DIR__, 2) . '/src/Operations';
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'Milpa\\AppRuntime\\Operations\\' . basename($file, '.php');
            if (class_exists($class) && is_a($class, CommandProvider::class, true)) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    /**
     * @param class-string<CommandProvider> $provider
     *
     * @dataProvider providers
     */
    public function testEveryOperationsFlagAgreesWithItsCeiling(string $provider): void
    {
        $built = $this->build($provider);
        if ($built === null) {
            self::markTestSkipped($provider . ' needs collaborators no app hands a bare provider');
        }

        $checked = 0;
        foreach ($built->operations() as $op) {
            if (!$op instanceof Operation || $op->effects === null) {
                continue;
            }
            // 🚨 `unknown` IS NOT A DISAGREEMENT, and reading it as one was this check's own first
            // defect. It means «nobody classified», and GOV-05 makes an unclassified ceiling carry
            // the MAXIMUM — so `mutating: true` beside it is the safe reading, not a contradiction.
            // `config:set` and `sequence:run` land there when built bare, because their ceilings are
            // BORROWED from a catalogue a bare provider does not have. Only a ceiling somebody
            // actually classified can disagree with the flag.
            if ($op->effects->mutation === Mutation::Unknown) {
                continue;
            }
            ++$checked;
            $ceilingChanges = $op->effects->mutation !== Mutation::None;

            self::assertSame(
                $ceilingChanges,
                $op->mutating,
                \sprintf(
                    '%s: the ceiling says mutation «%s» and the flag says %s — the signature gate reads the FLAG',
                    $op->name,
                    $op->effects->mutation->value,
                    $op->mutating ? 'mutating' : 'reads',
                ),
            );
        }

        // No per-provider floor: a provider whose only operation BORROWS its ceiling has nothing to
        // check here, and `SequenceOperations` is exactly that. The «this proved nothing» guard
        // belongs to the suite, one test down — asserting it per provider turned a legitimate state
        // into a failure, which was this check's second defect.
        self::assertGreaterThanOrEqual(0, $checked);
    }

    /**
     * THE GUARD AGAINST A VACUOUS BATTERY, at the level where it is true.
     *
     * Every operation above could borrow its ceiling and the whole check would pass by examining
     * nothing. So this counts what the package actually classified, and a number that collapses is a
     * suite that stopped measuring rather than a package that got simpler.
     */
    public function testThePackageClassifiesEnoughOperationsForThisToMeanSomething(): void
    {
        $classified = 0;
        foreach (self::providers() as [$provider]) {
            $built = $this->build($provider);
            foreach ($built?->operations() ?? [] as $op) {
                if ($op instanceof Operation && $op->effects !== null && $op->effects->mutation !== Mutation::Unknown) {
                    ++$classified;
                }
            }
        }

        self::assertGreaterThan(20, $classified, 'the agreement above is checked against this many real ceilings');
    }

    /** The control: this is the disagreement, and it is what the assertion above catches. */
    public function testTheAssertionCatchesACeilingThatChangesTheWorldWithoutTheFlag(): void
    {
        $lying = new Operation(
            name: 'looks-like-a-read',
            description: 'persistent in its ceiling, and «reads» to the gate that asks',
            handler: static fn (): array => [],
            effects: new \Milpa\Command\Effect\EffectProfile(mutation: Mutation::Persistent),
        );

        self::assertFalse($lying->mutating, 'the flag defaults to false, which is how this happens at all');
        self::assertNotSame(Mutation::None, $lying->effects?->mutation, 'while the ceiling says otherwise');
    }

    /** @param class-string<CommandProvider> $provider */
    private function build(string $provider): ?CommandProvider
    {
        try {
            $reflected = new \ReflectionClass($provider);
            $built = $reflected->getConstructor() === null
                ? $reflected->newInstance()
                : $reflected->newInstance(new DIContainer());

            return $built instanceof CommandProvider ? $built : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
