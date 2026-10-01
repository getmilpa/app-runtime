<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\OfferedTools;
use Milpa\AppRuntime\Agent\PrerequisiteGate;
use Milpa\AppRuntime\Agent\SessionOptionTable;
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
 * An operator's spelling meets the catalogue's by identity (greenhouse decisions/0550).
 *
 * Measured on fresh cattle before this: `--first=recipe.plan` refused `recipe_plan` itself and the leg ended on its
 * first step; `--deny=recipe.apply` was recorded and `recipe_apply` stayed offered; an unknown name was accepted.
 */
final class ToolNamesResolveByIdentityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function spellings(): iterable
    {
        yield 'the dot an operator types' => ['recipe.plan'];
        yield 'the colon the terminal prints' => ['recipe:plan'];
        yield 'the catalogue spelling itself' => ['recipe_plan'];
        yield 'with stray whitespace' => ['  recipe.plan '];
    }

    #[DataProvider('spellings')]
    public function testAnObligationAdmitsTheToolItNamesHoweverSpelled(string $spelled): void
    {
        $gate = new PrerequisiteGate([trim($spelled)]);

        self::assertNull($gate->motivoParaEsperar('recipe_plan'), 'the one call that meets the obligation proceeds');
        $refusal = $gate->motivoParaEsperar('house_start');
        self::assertIsString($refusal);
        self::assertStringContainsString('«recipe_plan» runs first', $refusal, 'named as the model calls it');
        self::assertStringNotContainsString('todavía', $refusal, 'the refusal speaks English');

        $gate->anota('recipe_plan', true);
        self::assertSame([], $gate->pendientes(), 'running it under the catalogue spelling meets the obligation');
        self::assertNull($gate->motivoParaEsperar('house_start'));
    }

    public function testSeveralObligationsAreNamedInTheCatalogueSpelling(): void
    {
        $gate = new PrerequisiteGate(['plan', 'recipe:plan']);

        self::assertSame('«make» does not proceed yet: «plan», «recipe_plan» run first, which is what was asked first.', $gate->motivoParaEsperar('make'));
    }

    public function testAnOfferedNameResolvesExactlyOrByIdentity(): void
    {
        $offered = new OfferedTools(['recipe_plan', 'recipe_apply', 'plan', 'todo', '']);

        self::assertSame('recipe_plan', $offered->resolve('recipe_plan'));
        self::assertSame('recipe_plan', $offered->resolve('recipe.plan'));
        self::assertSame('recipe_apply', $offered->resolve('Recipe:Apply'));
        self::assertNull($offered->resolve('recipe.plann'));
        self::assertNull($offered->resolve('   '));
        self::assertSame(['recipe_plan', 'recipe_plann'], $offered->canonical('recipe.plan, recipe:plan,recipe.plann'));
        self::assertSame(['plan', 'todo'], $offered->canonical(['plan', 7, '', 'todo']));
        self::assertSame([], OfferedTools::listed(null));
    }

    public function testANameNobodyOffersIsRefusedWithTheNearestNames(): void
    {
        $offered = new OfferedTools(['recipe_plan', 'recipe_apply', 'house_start']);

        self::assertNull($offered->refusal('first', 'recipe.plan,house_start'));
        self::assertNull($offered->refusal('first', null));

        $refusal = $offered->refusal('first', 'recipe.plann');
        self::assertNotNull($refusal);
        self::assertFalse($refusal['ok']);
        self::assertStringContainsString('--first names a tool this session is not offered: «recipe.plann»', $refusal['error']);
        self::assertStringContainsString('«recipe_plan»', $refusal['hint']);

        $two = $offered->refusal('deny', ['zzzz', 'qqqq-qqqq']);
        self::assertNotNull($two);
        self::assertStringContainsString('--deny names tools', $two['error']);
        self::assertStringStartsWith('name tools as', $two['hint'], 'nothing near: it says where the names are listed');
    }

    public function testAWithdrawalRecordedInTheOperatorsSpellingWithdrawsTheTool(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'goal');
        $store->removeOption('s', 'recipe.apply', 'denied-by-operator');
        $table = new SessionOptionTable($store, 's');

        self::assertContains('recipe.apply', $table->removed(), 'the recorded fact keeps the operator words');
        self::assertContains('recipe_apply', $table->removed(), 'and the projection carries the tool the model is offered');
        self::assertTrue($table->wasRemoved('recipe_apply'));
        self::assertFalse($table->wasRemoved('recipe_plan'));
        self::assertSame([], (new SessionOptionTable($store, 'nobody'))->removed());
    }

    public function testAutoAsksForAnActNoGrantCanAdmitInsteadOfLettingItDieBelow(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'apply the notes recipe', AutonomyMode::Auto);

        $refusal = $this->gate($store)->refuse('lab_registry', ['what' => 'notes']);

        self::assertIsString($refusal, 'the call does not run unasked');
        self::assertSame('perm:lab:registry', $store->load('s')?->question?->id, 'it is the question ask would have asked');
    }

    public function testAutoKeepsCarryingOnForWhatAGrantCouldAdmit(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'register it', AutonomyMode::Auto);

        self::assertNull($this->gate($store)->refuse('lab_local', ['what' => 'notes']), 'decisions/0226 stands: the other door judges it');
        self::assertNull($store->load('s')?->question);
    }

    public function testAYesAlreadyGivenForTheActAdmitsItInAuto(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'apply the notes recipe', AutonomyMode::Auto);
        $store->grant('s', 'lab:registry');

        self::assertNull($this->gate($store)->refuse('lab_registry', ['what' => 'notes']));
        self::assertNull($store->load('s')?->question);
    }

    private function gate(SessionStore $store): SessionToolGate
    {
        $session = $store->load('s');
        self::assertNotNull($session);
        $handler = static fn (array $i): array => ['ok' => true];
        $schema = ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []];

        return new SessionToolGate($store, $session, [
            // recipe:apply's ceiling: it reaches the registry, so no launch grant can admit it.
            new Operation('lab:registry', 'reaches the registry', $handler, inputSchema: $schema, mutating: true, effects: new EffectProfile(
                Mutation::Persistent,
                Externality::ThirdParty,
                Reversibility::ManualRecovery,
                Authority::Privileged,
                subject: Subject::Executable,
            )),
            // plugins:register's ceiling: privileged but local, which a launch grant can admit.
            new Operation('lab:local', 'wires a plugin', $handler, inputSchema: $schema, mutating: true, effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::ManualRecovery,
                Authority::Privileged,
                subject: Subject::Executable,
            )),
        ]);
    }
}
