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
use Milpa\AppRuntime\Agent\LaunchGrants;
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
use Milpa\EventStore\FileEventStore;
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

    #[Test]
    public function an_argument_that_contains_the_unjudgeable_word_is_still_a_pause(): void
    {
        // The runner reads the UNJUDGEABLE marker as a PREFIX; a pause message embeds the human's own arguments,
        // and a substring match turned this consent pause into a hard deny (no cursor, prefix re-run).
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
            ['op' => 'lab:burn', 'args' => ['what' => 'note: UNJUDGEABLE by design']],
        ]]);
        $this->apply($recipe);
        $this->sayYes();

        $second = $this->apply($recipe);
        self::assertTrue($second['paused'] ?? false, json_encode($second));
        self::assertNotTrue($second['denied'] ?? false, 'a consent pause, not a hard deny');
        self::assertSame(1, $second['executed_count'] ?? null);
        $this->sayYes();

        $third = $this->apply($recipe);
        self::assertTrue($third['applied'] ?? false, json_encode($third));
        self::assertSame(['a', 'note: UNJUDGEABLE by design'], self::$burned, 'each once, in order');
    }

    #[Test]
    public function in_auto_mode_a_yes_for_other_arguments_asks_again_too(): void
    {
        // The mode is NOT consulted: auto means «do not ask for the reversible», never «do not ask». Before,
        // an auto session with a yes for other arguments fell as a plain failure at the other door.
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
            ['op' => 'lab:burn', 'args' => ['what' => 'b']],
        ]]);
        $this->apply($recipe);
        $this->sayYes();
        $this->store->setMode('recipe:demo', AutonomyMode::Auto);

        $second = $this->apply($recipe);
        self::assertTrue($second['paused'] ?? false, json_encode($second));
        self::assertSame(['what' => 'b'], $this->askedFor());
        self::assertSame(['a'], self::$burned);
        $this->sayYes();

        $third = $this->apply($recipe);
        self::assertTrue($third['applied'] ?? false, json_encode($third));
        self::assertSame(['a', 'b'], self::$burned);
    }

    #[Test]
    public function a_float_the_model_writes_as_one_point_zero_is_covered_by_the_yes_it_got(): void
    {
        // The model path never passes the call through the store: `{"ratio":1.0}` decodes as float, the fact
        // the ledger holds says int 1, and a strict comparison asked forever. Every door compares the call in
        // the ledger's shape now. Measured against the store the app really uses.
        $ledger = $this->root . '/ledger.jsonl';
        $this->store = new SessionStore(new FileEventStore($ledger));
        $this->store->start('recipe:demo', 'apply recipe demo', AutonomyMode::Ask);
        $call = json_decode('{"ratio":1.0,"what":"a"}', true);
        self::assertIsFloat($call['ratio'], 'the instrument: the model\'s JSON really carries a float');

        try {
            $this->door()->callTool('lab_burn', $call);
            self::fail('the first call pauses for consent');
        } catch (\Milpa\ToolRuntime\Gate\ToolCallRefused) {
        }
        $this->sayYes();
        $result = $this->door()->callTool('lab_burn', $call);
        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, 'one yes covers the call it was given for: ' . json_encode($result));
        self::assertSame(['a'], self::$burned);
    }

    #[Test]
    public function a_step_that_fails_after_a_consented_prefix_does_not_make_the_retry_re_run_the_prefix(): void
    {
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
            ['op' => 'lab:boom'],
        ]]);
        $this->apply($recipe);
        $this->sayYes();

        $second = $this->apply($recipe);
        self::assertFalse($second['ok'] ?? true, json_encode($second));
        self::assertFalse($second['paused'] ?? true, 'a failure is not a pause');
        self::assertSame('the read broke', $second['reason'] ?? null);
        self::assertSame(['a'], self::$burned);

        // THE RETRY resumes at the failed step — the consented prefix is carried, never re-run.
        $third = $this->apply($recipe);
        self::assertSame('the read broke', $third['reason'] ?? null, 'the failing step is what fails again');
        self::assertSame(['a'], self::$burned, 'the Privileged first step did not run twice');
    }

    #[Test]
    public function a_handler_that_dies_on_the_confirmed_call_is_a_failure_not_a_pending_confirmation(): void
    {
        $recipe = Recipe::fromArray('demo', ['work' => [['op' => 'lab:burn', 'args' => ['what' => 'BOOM']]]]);
        $this->apply($recipe);
        $this->sayYes();

        $second = $this->apply($recipe);
        self::assertFalse($second['ok'] ?? true, json_encode($second));
        self::assertFalse($second['paused'] ?? true, 'a tool that broke past the token is a failure, never a resumable pause');
        self::assertStringContainsString('the tool broke', (string) ($second['reason'] ?? ''));
    }

    #[Test]
    public function a_launch_grant_typed_by_the_schema_covers_the_call_the_model_makes(): void
    {
        // `--grant=lab:count:times=2` arrives as the string "2"; the model calls with int 2. The seeded fact is
        // typed as the schema declares, so the yes covers the call instead of asking (or failing) again.
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);
        $entries = LaunchGrants::parse('lab:count:times=2');
        self::assertIsArray($entries);
        $seeded = (new LaunchGrants())->seed($this->store, 'recipe:demo', $entries, $this->operations(), new Principal('actor:passkey:test', true));
        self::assertSame(['lab:count'], $seeded['seeded'] ?? null, json_encode($seeded));

        $result = $this->door()->callTool('lab_count', ['times' => 2]);
        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, 'the typed grant covers the int the model sends: ' . json_encode($result));
        self::assertNull($this->store->load('recipe:demo')?->question, 'no question was opened');
    }

    #[Test]
    public function a_step_that_makes_the_app_grow_is_followed_by_a_step_the_door_can_now_judge(): void
    {
        // The real first hour: `capabilities:enable milpa/devtools` writes a provider into config/operations.php,
        // and the NEXT step is `make`. A door born before the enable called it UNJUDGEABLE (measured on cattle).
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [];\n");
        $door = $this->door();

        // THE CONTROL: before the app grew, the door does not invent the operation — it is unjudgeable.
        try {
            $door->callTool('lab_new', []);
            self::fail('an operation the app does not offer yet cannot be judged');
        } catch (\Milpa\ToolRuntime\Gate\ToolCallRefused $refused) {
            self::assertStringStartsWith('UNJUDGEABLE:', $refused->getMessage());
        }

        // The app grows: a provider lands in config/operations.php, the way the enable writes it.
        \Milpa\AppRuntime\Support\Capabilities::registerOperations($this->root, [GrownProvider::class]);
        $result = $door->callTool('lab_new', ['what' => 'x']);
        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, 'the same door now judges and runs what the app just learned: ' . json_encode($result));
        self::assertSame('grown:x', $result['says'] ?? null);
    }

    #[Test]
    public function a_step_the_gate_denies_after_a_consented_prefix_does_not_make_the_retry_re_run_the_prefix(): void
    {
        // `lab:nope` is no operation of this app: unjudgeable, a hard deny. On resume, the pause the run was
        // resumed from must move to the denied step — or the retry would re-run the Privileged prefix.
        $recipe = Recipe::fromArray('demo', ['work' => [
            ['op' => 'lab:burn', 'args' => ['what' => 'a']],
            ['op' => 'lab:nope'],
        ]]);
        $this->apply($recipe);
        $this->sayYes();

        $second = $this->apply($recipe);
        self::assertTrue($second['denied'] ?? false, json_encode($second));
        self::assertSame(['a'], self::$burned);
        $paused = $this->store->load('recipe:demo')?->pausedSequence;
        self::assertNotNull($paused);
        self::assertSame(1, $paused->nextIndex, 'the pause moved to the denied step');

        $third = $this->apply($recipe);
        self::assertTrue($third['denied'] ?? false, 'still not offered, still denied: ' . json_encode($third));
        self::assertSame(['a'], self::$burned, 'the Privileged first step did not run twice');
    }

    #[Test]
    public function what_the_enable_installed_a_moment_ago_is_loadable_and_judged_in_the_same_process(): void
    {
        // The booted autoloader keeps the maps it was born with: a provider composer just installed stayed
        // «class not found» for the very sequence that installed it. `Capabilities::install` teaches the
        // running loader the tree composer wrote, so the door's re-fold sees the class NOW.
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [];\n");
        mkdir($this->root . '/grown');
        $class = 'LabGrown\\Ops\\GrownOnDiskProvider';
        file_put_contents($this->root . '/grown/GrownOnDiskProvider.php', <<<'PHP_'
            <?php
            namespace LabGrown\Ops;
            final class GrownOnDiskProvider implements \Milpa\Command\CommandProvider
            {
                public function __construct(\Milpa\Interfaces\Di\DIContainerInterface $container) {}
                public function operations(): array
                {
                    return [new \Milpa\Command\Operation(
                        name: 'lab:disk',
                        description: 'an operation installed a moment ago',
                        handler: static fn (array $input): array => ['ok' => true, 'says' => 'disk:' . ($input['what'] ?? '')],
                        inputSchema: ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []],
                        effects: \Milpa\Command\Effect\EffectProfile::readOnly(),
                    )];
                }
            }
            PHP_);
        self::assertFalse(class_exists($class), 'the instrument: no loader maps the namespace yet');
        $door = $this->door();
        try {
            $door->callTool('lab_disk', []);
            self::fail('unjudgeable before the install');
        } catch (\Milpa\ToolRuntime\Gate\ToolCallRefused $refused) {
            self::assertStringStartsWith('UNJUDGEABLE:', $refused->getMessage());
        }

        // THE ENABLE, as it happens: composer writes the tree (maps + installed.json), then install() registers.
        $before = $this->vendorWith([]);
        $after = $this->vendorWith([[
            'name' => 'lab/grown', 'version' => '1.0.0',
            'extra' => ['milpa' => ['capability' => ['id' => 'grown', 'title' => 'grown', 'unlocks' => [], 'provides' => [], 'operations' => [$class]]]],
        ]]);
        file_put_contents($after . '/composer/autoload_psr4.php', "<?php\n\nreturn ['LabGrown\\\\Ops\\\\' => [" . var_export($this->root . '/grown', true) . "]];\n");
        file_put_contents($after . '/composer/autoload_classmap.php', "<?php\n\nreturn [];\n");
        $answer = \Milpa\AppRuntime\Support\Capabilities::install(
            'lab/grown',
            $before,
            static fn (string $command): array => [0, []],
            index: ['capabilities' => ['lab/grown' => ['id' => 'grown', 'title' => 'grown', 'unlocks' => [], 'version' => '1.0.0']]],
            vendorAfter: $after,
            root: $this->root,
        );
        self::assertTrue($answer['ok'] ?? false, json_encode($answer));
        self::assertSame([$class], $answer['registered'] ?? null, 'the provider was written into config/operations.php');

        $result = $door->callTool('lab_disk', ['what' => 'y']);
        self::assertIsArray($result);
        self::assertSame('disk:y', $result['says'] ?? null, 'loadable and judged in the same process: ' . json_encode($result));
    }

    /** @param list<array<string, mixed>> $packages */
    private function vendorWith(array $packages): string
    {
        $dir = $this->root . '/vendor-' . bin2hex(random_bytes(3));
        mkdir($dir . '/composer', 0o775, true);
        file_put_contents($dir . '/composer/installed.json', json_encode(['packages' => $packages], \JSON_THROW_ON_ERROR));

        return $dir;
    }

    private function door(): \Milpa\AppRuntime\Agent\ConsentBridge
    {
        $session = $this->store->load('recipe:demo');
        self::assertInstanceOf(Session::class, $session);

        return GovernedDoor::open($this->kernel(), $this->root, $this->store, $session, 'apply recipe demo');
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

    /** @return list<Operation> */
    private function operations(): array
    {
        $s2 = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Executable);
        $burn = new Operation(
            name: 'lab:burn',
            description: 'a governed write only a human may authorize',
            handler: static function (array $input): array {
                if (($input['what'] ?? null) === 'BOOM') {
                    throw new \RuntimeException('the tool broke');
                }
                self::$burned[] = (string) ($input['what'] ?? '');

                return ['ok' => true, 'burned' => $input['what'] ?? null];
            },
            inputSchema: ['type' => 'object', 'properties' => ['what' => ['type' => 'string'], 'ratio' => ['type' => 'number']], 'required' => []],
            mutating: true,
            effects: $s2,
        );
        $boom = new Operation(
            name: 'lab:boom',
            description: 'a declared read that breaks',
            handler: static function (array $input): array {
                throw new \RuntimeException('the read broke');
            },
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            effects: EffectProfile::readOnly(),
        );
        $count = new Operation(
            name: 'lab:count',
            description: 'a governed write with an integer argument',
            handler: static fn (array $input): array => ['ok' => true, 'times' => $input['times'] ?? null],
            inputSchema: ['type' => 'object', 'properties' => ['times' => ['type' => 'integer']], 'required' => []],
            mutating: true,
            effects: $s2,
        );

        return [$burn, $boom, $count];
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

/** What `capabilities:enable` declares in config/operations.php: a provider that was not there when the door opened. */
final class GrownProvider implements \Milpa\Command\CommandProvider
{
    public function __construct(\Milpa\Interfaces\Di\DIContainerInterface $container)
    {
    }

    public function operations(): array
    {
        return [new Operation(
            name: 'lab:new',
            description: 'an operation the app learned mid-sequence',
            handler: static fn (array $input): array => ['ok' => true, 'says' => 'grown:' . ($input['what'] ?? '')],
            inputSchema: ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []],
            effects: EffectProfile::readOnly(),
        )];
    }
}
