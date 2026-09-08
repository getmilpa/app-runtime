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
 * A yes for other arguments asks again (greenhouse decisions/0226, F1 and its control).
 *
 * The session allows by NAME; the tool-runtime gate admits by the exact CALL; the grant the ledger holds
 * carries the arguments the human saw. A recipe of two `lab:burn` steps with different arguments must
 * pause twice — once per call — and never re-run the first step. The control: the same arguments twice
 * apply after ONE yes, with no second question.
 */
final class AYesForOtherArgumentsAsksAgainTest extends TestCase
{
    /** @var list<string> what the handler burned, in order */
    private static array $burned = [];

    private string $root;

    private SessionStore $store;

    protected function setUp(): void
    {
        self::$burned = [];
        $this->root = sys_get_temp_dir() . '/yes-for-other-args-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('recipe:demo', 'apply recipe demo', AutonomyMode::Ask);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function two_calls_with_different_arguments_pause_twice_and_the_first_never_re_runs(): void
    {
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
            ['op' => 'lab:burn', 'args' => ['what' => 'b']],
        ]]);

        $first = $this->apply($recipe);
        self::assertTrue($first['paused'] ?? false, json_encode($first));
        self::assertSame(0, $first['executed_count'] ?? null);
        self::assertSame(['what' => 'a'], $this->askedFor(), 'the first pause asks for the first call');
        $this->sayYes();

        // THE MEASUREMENT: a yes recorded for {what:a} does not cover {what:b}. Before 0226 this call fell to
        // the tool-runtime gate as a plain failure («none was presented»), no cursor was written, and every
        // retry re-ran step 1. Now the session door asks again — for these arguments.
        $second = $this->apply($recipe);
        self::assertTrue($second['paused'] ?? false, 'a yes for other arguments must ask again, not fail: ' . json_encode($second));
        self::assertSame(1, $second['executed_count'] ?? null, 'the first step ran once and the cursor moved');
        self::assertSame(['what' => 'b'], $this->askedFor(), 'the second pause asks for THESE arguments');
        self::assertSame(['a'], self::$burned, 'nothing ran twice');
        $this->sayYes();

        $third = $this->apply($recipe);
        self::assertTrue($third['applied'] ?? false, json_encode($third));
        self::assertSame(2, $third['executed_count'] ?? null);
        self::assertSame(['a', 'b'], self::$burned, 'each call ran exactly once, in order');
    }

    #[Test]
    public function the_control_the_same_arguments_twice_apply_after_one_yes(): void
    {
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
        ]]);

        $first = $this->apply($recipe);
        self::assertTrue($first['paused'] ?? false, json_encode($first));
        $this->sayYes();

        $second = $this->apply($recipe);
        self::assertTrue($second['applied'] ?? false, 'the same call twice needs one yes: ' . json_encode($second));
        self::assertFalse($second['paused'] ?? false, 'no second question for the same arguments');
        self::assertSame(['a', 'a'], self::$burned);
    }

    /** @return array<string, mixed> */
    private function apply(Recipe $recipe): array
    {
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);
        $door = GovernedDoor::open($this->kernel(), $this->root, $this->store, $session, 'apply recipe demo');
        // AS `recipe:apply` DISPATCHES: a session holding a pause is resumed from its cursor; otherwise the
        // recipe is expanded and run from the start.
        if ($session->pausedSequence !== null) {
            return (new RecipeDriver())->resume($this->store, 'recipe:demo', $door);
        }

        return (new RecipeDriver())->apply(
            $recipe,
            $door,
            $this->store,
            'recipe:demo',
            static fn (): array => ['verdict' => 'founded', 'domain' => 'demo'],
            static fn (): array => [],
        );
    }

    /** The arguments the open question carries — the fact the human is shown. */
    private function askedFor(): mixed
    {
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);
        self::assertNotNull($session->question, 'a question is open');
        $fact = json_decode((string) $session->question->why, true);
        self::assertIsArray($fact);
        self::assertSame('lab:burn', $fact['operation'] ?? null);

        return $fact['arguments'] ?? null;
    }

    /** What `agent:answer` does with a «sí»: records the answer and the permission by name. */
    private function sayYes(): void
    {
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);
        self::assertNotNull($session->question);
        $this->store->answer('recipe:demo', $session->question->id, 'sí', new Principal('actor:passkey:test', true), 'rod@desktop');
        $this->store->grant('recipe:demo', 'lab:burn');
    }

    private function kernel(): Kernel
    {
        $burn = new Operation(
            name: 'lab:burn',
            description: 'a governed write only a human may authorize',
            handler: static function (array $input): array {
                self::$burned[] = (string) ($input['what'] ?? '');

                return ['ok' => true, 'burned' => $input['what'] ?? null];
            },
            inputSchema: ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Executable),
        );
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $this->root, 'commands' => [$burn], 'container' => new DIContainer()] as $name => $value) {
            $prop = new \ReflectionProperty(Kernel::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($kernel, $value);
        }

        return $kernel;
    }
}
