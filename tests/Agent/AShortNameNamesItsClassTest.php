<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A short name names its class (greenhouse decisions/0501).
 *
 * Measured in evidence/1029: the resident called `plugins.register App\Plugins\Blog\Blog` under a goal
 * that said «a plugin named Blog», and the intent contract parked `target_not_named` — it looked for the
 * whole qualified value inside the ask, and nobody writes a namespace into a goal. A qualified class
 * value now also counts as named when the standing ask names its LAST segment as a whole identifier,
 * ignoring case (the frontier's judge, decisions/0496 §3). Everything else keeps asking.
 *
 * Run in auto mode, which exempts permission, so what remains is the intent contract alone.
 */
final class AShortNameNamesItsClassTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function asks(): iterable
    {
        yield 'the goal of 1029 names the plugin by its short name' => ['Build a tiny blog: a plugin named Blog that serves /blog.', 'App\\Plugins\\Blog\\Blog', false];
        yield 'a lowercase short name still names it (decisions/0009)' => ['build the blog', 'App\\Plugins\\Blog\\Blog', false];
        yield 'a leading backslash is the same class' => ['a plugin named Blog', '\\App\\Plugins\\Blog\\Blog', false];
        yield 'the whole value named keeps passing (rule 1)' => ['register App\\Plugins\\Blog\\Blog', 'App\\Plugins\\Blog\\Blog', false];
        yield 'a short name inside another word is not named' => ['a blogging platform', 'App\\Plugins\\Blog\\Blog', true];
        yield 'the invented name of 1028 is not named' => ['a plugin named Blog', 'App\\Plugins\\BlogPlugin\\BlogPlugin', true];
        yield 'only the last segment counts' => ['the Blog plugin', 'App\\Plugins\\Blog\\BlogController', true];
        yield 'an intermediate segment does not name the class' => ['list the plugins', 'App\\Plugins\\Blog\\Blog', true];
        yield 'the tidy goal of 1028 still asks (the bare name)' => ['Tidy the house: the demo plugin that came with the skeleton should not boot anymore.', 'HelloPlugin', true];
        yield 'the tidy goal of 1028 still asks (the qualified name)' => ['Tidy the house: the demo plugin that came with the skeleton should not boot anymore.', 'App\\Plugins\\HelloPlugin\\HelloPlugin', true];
        yield 'an unqualified short name is not judged by the new rule' => ['a blogging platform', 'Blog', false];
    }

    #[DataProvider('asks')]
    public function testTheIntentContractJudgesTheShortName(string $ask, string $target, bool $parks): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', $ask, AutonomyMode::Auto);
        $gate = new SessionToolGate($store, $store->load('s1'), [$this->register()], petition: 'continue');

        $refusal = $gate->refuse('plugins_register', ['name' => $target]);

        if ($parks) {
            self::assertNotNull($refusal, "«{$ask}» does not name «{$target}»");
            self::assertSame('target_not_named', $store->load('s1')?->question?->reason);
            // Asked in the house's language (decisions/0514): 1036 parked this question in Spanish.
            self::assertDoesNotMatchRegularExpression('/[áéíóúñ¿¡]|\\b(que|para|desde|del|los|las|una|esta|este|siguiente|corre|arranca|nombre|pendiente|objetivo|sesi[oó]n|autorizas?|petici[oó]n|nombra|hecho|resumen|herramientas|contesta|pídele|dile|confirmas|sobre|quiere|correr)\\b/iu', (string) $store->load('s1')?->question?->question);
            self::assertMatchesRegularExpression('/^The request does not name «[^»]+»\. Confirm \S+ on «[^»]+»\?$/u', (string) $store->load('s1')?->question?->question);
        } else {
            self::assertNull($refusal, "«{$ask}» names «{$target}»");
            self::assertNull($store->load('s1')?->question, 'nothing is left parked');
        }
    }

    /**
     * The named cost of ignoring case (decisions/0496 §3, 0501): a common word of the goal names itself,
     * so «a plugin named Blog» also names a class whose short name is `Plugin`. Pinned as a cost, not a
     * guarantee.
     */
    public function testACommonWordOfTheGoalNamesItself(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'a plugin named Blog', AutonomyMode::Auto);
        $gate = new SessionToolGate($store, $store->load('s1'), [$this->register()], petition: 'continue');

        self::assertNull($gate->refuse('plugins_register', ['name' => 'App\\Plugins\\Plugin\\Plugin']));
    }

    /**
     * The register operation: selecting (it does not create its target) and privileged, so neither the
     * materialising relaxation (decisions/0187) nor anything else waives the question.
     */
    private function register(): Operation
    {
        return new Operation(
            'plugins.register',
            'Declare a plugin that already exists in this app so the kernel boots it.',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
            mutating: true,
            namedTarget: 'name',
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::ManualRecovery,
                Authority::Privileged,
                subject: Subject::Executable,
            ),
        );
    }
}
