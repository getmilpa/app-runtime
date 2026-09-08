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

namespace Milpa\AppRuntime\Tests\Sequence;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Recipe\Recipe;
use Milpa\AppRuntime\Recipe\RecipeDriver;
use Milpa\AppRuntime\Sequence\GovernedDoor;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A read with no EffectProfile asks once; a declared read never does (greenhouse decisions/0227).
 *
 * The other door (tool-runtime, channel cli) demands a covering yes for an operation whose ceiling is
 * Unknown on every axis — and used to get none, because the session's read arm never asked: the call fell
 * as a plain failure («none was presented»). Now the session door asks by the same fact.
 */
final class AReadWithoutAProfileAsksOnceTest extends TestCase
{
    /** @var list<string> */
    private static array $ran = [];

    private string $root;

    private SessionStore $store;

    protected function setUp(): void
    {
        self::$ran = [];
        $this->root = sys_get_temp_dir() . '/read-no-profile-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('recipe:demo', 'apply recipe demo', AutonomyMode::Ask);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function at_the_gate_an_undeclared_read_asks_once_and_a_declared_read_never_does(): void
    {
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);
        $gate = new SessionToolGate($this->store, $session, $this->operations());

        self::assertNull($gate->refuse('lab_declared', []), 'a declared read passes, no question');
        self::assertNull($this->store->load('recipe:demo')?->question);

        $reason = $gate->refuse('lab_peek', ['what' => 'x']);
        self::assertNotNull($reason, 'a read that declares nothing is Unknown on every axis: it asks');
        self::assertStringNotContainsString(SessionToolGate::UNJUDGEABLE, $reason, 'asked, not denied: it is an operation of this app');
        self::assertNotNull($this->store->load('recipe:demo')?->question, 'the question is open for a human');
    }

    #[Test]
    public function in_a_sequence_the_undeclared_read_pauses_once_then_runs_and_the_declared_one_never_pauses(): void
    {
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
            ['op' => 'lab:peek', 'args' => ['what' => 'x']],
            ['op' => 'lab:declared'],
        ]]);
        $this->apply($recipe);
        $this->sayYes('lab:burn');

        // THE MEASUREMENT: before 0227 this fell as «needs explicit consent … none was presented».
        $second = $this->apply($recipe);
        self::assertTrue($second['paused'] ?? false, 'the undeclared read pauses for a yes: ' . json_encode($second));
        self::assertSame(1, $second['executed_count'] ?? null);
        self::assertSame(['burn:a'], self::$ran);
        $this->sayYes('lab:peek');

        $third = $this->apply($recipe);
        self::assertTrue($third['applied'] ?? false, json_encode($third));
        self::assertSame(['burn:a', 'peek:x', 'declared'], self::$ran, 'the declared read ran without a pause of its own');
    }

    #[Test]
    public function the_yes_for_an_undeclared_read_covers_the_call_it_was_given_for(): void
    {
        // An Unknown ceiling is judged like any consent-demanding call: by the exact call (decisions/0226).
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:peek', 'args' => ['what' => 'x']],
            ['op' => 'lab:peek', 'args' => ['what' => 'x']],
            ['op' => 'lab:peek', 'args' => ['what' => 'y']],
        ]]);
        $this->apply($recipe);
        $this->sayYes('lab:peek');

        $second = $this->apply($recipe);
        self::assertTrue($second['paused'] ?? false, json_encode($second));
        self::assertSame(2, $second['executed_count'] ?? null, 'the same call twice needed one yes; the third call asks for its own');
        $this->sayYes('lab:peek');

        $third = $this->apply($recipe);
        self::assertTrue($third['applied'] ?? false, json_encode($third));
        self::assertSame(['peek:x', 'peek:x', 'peek:y'], self::$ran);
    }

    /** @return array<string, mixed> */
    private function apply(Recipe $recipe): array
    {
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);
        $door = GovernedDoor::open($this->kernel(), $this->root, $this->store, $session, 'apply recipe demo');
        if ($session->pausedSequence !== null) {
            return (new RecipeDriver())->resume($this->store, 'recipe:demo', $door);
        }

        return (new RecipeDriver())->apply($recipe, $door, $this->store, 'recipe:demo', static fn (): array => ['verdict' => 'founded', 'domain' => 'demo'], static fn (): array => []);
    }

    private function sayYes(string $operation): void
    {
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);
        self::assertNotNull($session->question, 'a question is open');
        $this->store->answer('recipe:demo', $session->question->id, 'sí', new Principal('actor:passkey:test', true), 'rod@desktop');
        $this->store->grant('recipe:demo', $operation);
    }

    /** @return list<Operation> */
    private function operations(): array
    {
        $schema = ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []];

        return [
            new Operation(
                name: 'lab:burn',
                description: 'a governed write only a human may authorize',
                handler: static function (array $input): array {
                    self::$ran[] = 'burn:' . ($input['what'] ?? '');

                    return ['ok' => true];
                },
                inputSchema: $schema,
                mutating: true,
                effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Executable),
            ),
            // NO EffectProfile: the shape a hand-written read has when nobody declared what it does.
            new Operation(
                name: 'lab:peek',
                description: 'a read that declares nothing about its effects',
                handler: static function (array $input): array {
                    self::$ran[] = 'peek:' . ($input['what'] ?? '');

                    return ['ok' => true];
                },
                inputSchema: $schema,
            ),
            new Operation(
                name: 'lab:declared',
                description: 'a read that says so',
                handler: static function (array $input): array {
                    self::$ran[] = 'declared';

                    return ['ok' => true];
                },
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
                effects: EffectProfile::readOnly(),
            ),
        ];
    }

    private function kernel(): Kernel
    {
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $this->root, 'commands' => $this->operations(), 'container' => new DIContainer()] as $name => $value) {
            $prop = new \ReflectionProperty(Kernel::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($kernel, $value);
        }

        return $kernel;
    }
}
