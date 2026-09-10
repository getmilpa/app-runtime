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
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 AN OPERATION THAT DEMANDS CONSENT MUST ALSO DEMAND IDENTITY, and shipping one that did not was a
 * hole measured on cattle.
 *
 * `provider:declare` demanded a signature on the CLI and nothing on HTTP. Exposed over HTTP, TWO
 * SAME-ORIGIN POSTS WITH NO SESSION wrote a provider credential: a `428` handed out a confirm token,
 * the token came back, `201 Created`, the key on disk. No identity, no signature, no principal.
 *
 * The framework refuses at BOOT to expose an operation that «demands identity» without an
 * `OperationHttpPolicy` to judge it — and it reads exactly two things, `scopes` and `permission`. An
 * operation that declares neither walks past that guard however loudly it demands consent elsewhere.
 *
 * So consent and identity are held together here: `requiresConfirmation` is what the CLI reads to
 * demand a signature, and a scope is what a permission-aware surface reads to demand a principal. A
 * gate that is right on one surface and absent on the other is a gate at the height of the lower one
 * (greenhouse decisions/0274).
 *
 * The reasoning was already in this package, written on `identity:enroll`: «the gate enforces this on a
 * permission-aware surface: only a principal that carries identity:enroll may enroll another. WITHOUT
 * IT THE DOOR WOULD BE OPEN ON HTTP.» This test is that sentence, applied to every operation instead of
 * remembered for one.
 */
final class ConsentDemandsIdentityTest extends TestCase
{
    /**
     * The consent-demanding operations that need no scope because THEIR HANDLER REFUSES ON ITS OWN,
     * each with the reason — and the list is short on purpose.
     *
     * 🚨 THIS IS THE THIRD SHAPE OF THIS TEST AND THE OTHER TWO ARE THE LESSON.
     *
     * The first asserted the structure with no exceptions and flagged `session:own`, which needs no
     * scope: its handler reads the granted authorization and refuses without one, because there the
     * signature is not a gate around the act but the payload itself — «the signed payload IS the
     * assertion this operation stores». Demanding a scope from that is ceremony.
     *
     * The second RAN each handler with empty input and asserted a refusal. It passed with
     * `provider:declare`'s scope removed — the very hole it was written for — because an empty input
     * makes a well-formed operation refuse for the WRONG REASON. «No key given» and «no principal
     * given» are different refusals and that test could not tell them apart. Feeding valid input
     * instead would have written real credentials to disk during the suite.
     *
     * So: the rule is structural and its exceptions are NAMED, the way this house names the words that
     * are the same word in two languages and the ceilings that are legitimately unknown. A list somebody
     * must edit is a list somebody must justify (greenhouse decisions/0274).
     *
     * @var array<string, string> operation name => why it needs no scope
     */
    private const array JUDGES_ITSELF = [
        'session:own' => 'its handler requires the GrantedAuthorization and refuses without it, and checks that '
            . 'the authorization names this operation AND this session — the signature is the payload, not a gate',
    ];

    /**
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
    public function testEveryOperationThatDemandsConsentAlsoDemandsIdentity(string $provider): void
    {
        $built = $this->build($provider);
        if ($built === null) {
            self::markTestSkipped($provider . ' needs collaborators no app hands a bare provider');
        }

        foreach ($built->operations() as $op) {
            if (!$op instanceof Operation) {
                continue;
            }
            // 🚨 ASKED OF `Consent`, NOT READ OFF THE FLAG — and that was this test's third defect.
            //
            // It read `requiresConfirmation`, so it never looked at `config:set`, whose consent is not
            // declared but DERIVED: rule S2 over the ceiling it borrows from the catalogue. The CLI
            // demanded `--sign` for it while this test said nothing, and two same-origin POSTs with no
            // session redirected `agent.baseUrl` — where every prompt the agent sends goes
            // (greenhouse decisions/0278).
            //
            // A check that re-derives a decision another component owns will drift from it. `Consent`
            // is that owner; the CLI asks it, so this asks it.
            if (!Consent::demanded($op)) {
                continue;
            }
            if ($op->scopes !== [] || $op->permission !== null) {
                // A policy can judge it: the boot guard sees the scope and refuses to expose it
                // unguarded, which is the whole point of declaring one.
                continue;
            }
            self::assertArrayHasKey(
                $op->name,
                self::JUDGES_ITSELF,
                \sprintf(
                    '«%s» demands consent, declares no scope or permission, and is not on the list of operations '
                    . 'whose handler refuses on its own: on a permission-aware surface its signature requirement '
                    . 'becomes a confirm token the caller is handed',
                    $op->name,
                ),
            );
        }
    }

    /** The guard against a vacuous battery: this package really does declare consent-demanding operations. */
    public function testThePackageDeclaresEnoughConsentingOperationsForThisToMeanSomething(): void
    {
        $consenting = [];
        foreach (self::providers() as [$provider]) {
            foreach ($this->build($provider)?->operations() ?? [] as $op) {
                if ($op instanceof Operation && Consent::demanded($op)) {
                    $consenting[] = $op->name;
                }
            }
        }

        self::assertGreaterThan(1, \count($consenting), 'the assertion above is checked against this many real operations');
        self::assertContains('provider:declare', $consenting, 'including the one whose absence was measured as a hole');
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
