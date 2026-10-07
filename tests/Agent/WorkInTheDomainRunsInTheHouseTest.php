<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use App\Plugins\Prestamos\DeclaringPlugin;
use App\Plugins\Prestamos\PlainPlugin;
use Milpa\AppRuntime\Agent\ConfinedWork;
use Milpa\AppRuntime\Agent\HouseWork;
use Milpa\AppRuntime\Agent\LandedCalls;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A call of work in the domain does not go to a trial: it runs once, in the house (greenhouse decisions/0588,
 * rule 1).
 *
 * Measured before this (decisions/0585, sessions `w3` and `w4`): `herramientas_agregar` ran in a trial's copy, which
 * is born without the domain's state, answered `ok: true`, `id: 1`, «there is nothing to apply» — and the house did
 * not change. Now the trial layer sets work apart by what its operation declares and runs it against the house's own
 * state, confined to it; what comes back is the handler's answer and, beside it, the house's account of its state.
 *
 * @guards a work call runs in the house and its state changes there; no copy is made and nothing is left to
 *         promote; the answer says what the state was and is, and says «it did not change» when it did not; a state
 *         that is not a place for state is refused and nothing runs; authoring still goes to a trial; the house
 *         remembers what it saw of the call it just ran, once, for the receipt
 *
 * @refuses rehearsing work against a copy without its state; running a call whose state points at configuration;
 *          handing the receipt facts the trial layer did not see itself
 *
 * @subject-in milpa/app-runtime
 */
final class WorkInTheDomainRunsInTheHouseTest extends TestCase
{
    private string $root;
    private string $bwrap;
    /** @var list<array{string, array<string, mixed>}> the calls that reached a handler in this process */
    private array $inProcess = [];

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/Fixtures/work-plugins.php';
    }

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-work-house-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Prestamos/Entities', 0o755, true);
        mkdir($this->root . '/var', 0o755, true);
        mkdir($this->root . '/config', 0o755, true);
        file_put_contents($this->root . '/src/Plugins/Prestamos/Entities/Herramienta.php', '<?php // entity');
        file_put_contents($this->root . '/var/herramientas.json', '[]');
        $this->bwrap = $this->root . '-bwrap';
        file_put_contents($this->bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($this->bwrap, 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->bwrap);
    }

    public function testAWorkCallRunsOnceInTheHouseAndItsStateChangesThere(): void
    {
        $result = $this->registry()->call('herramientas_agregar', ['nombre' => 'Sierra']);

        self::assertTrue($result->success, (string) $result->error);
        self::assertSame([['id' => 1, 'nombre' => 'Sierra', 'prestada' => false]], json_decode((string) file_get_contents($this->root . '/var/herramientas.json'), true), 'the house\'s own store');
        self::assertTrue($result->data['ran_in_house']);
        self::assertTrue($result->data['changed']);
        self::assertSame(['ok' => true, 'id' => 1], $result->data['output']);
        self::assertSame('var/herramientas.json', $result->data['state'][0]['path']);
        self::assertSame('sha256:' . hash('sha256', '[]'), $result->data['state'][0]['before']);
        self::assertSame('sha256:' . hash_file('sha256', $this->root . '/var/herramientas.json'), $result->data['state'][0]['after']);
        self::assertStringContainsString('ran in the house', $result->data['note']);
        self::assertStringContainsString('nothing to promote', $result->data['note']);
        self::assertSame([], $this->inProcess, 'the handler ran in the confined child, never in this process');
    }

    public function testItIsNotARehearsal(): void
    {
        $result = $this->registry()->call('herramientas_agregar', ['nombre' => 'Sierra']);

        foreach (['ran_in_trial', 'workspace', 'to_apply', 'to_discard', 'applied'] as $ofATrial) {
            self::assertArrayNotHasKey($ofATrial, $result->data, $ofATrial);
        }
        self::assertFalse(LandedCalls::keptInATrial($result->data), 'what reads «did it reach the house» does not take it for a rehearsal');
        self::assertDirectoryDoesNotExist($this->root . '/var/trials', 'no copy of the house was made');
    }

    public function testAnOkThatWroteNothingSaysTheStateDidNotChange(): void
    {
        $result = $this->registry()->call('herramientas_agregar', ['fixture' => 'nothing']);

        self::assertTrue($result->success);
        self::assertSame(['ok' => true, 'id' => 1], $result->data['output'], 'what the handler answered');
        self::assertFalse($result->data['changed'], 'what the house saw');
        self::assertStringContainsString('did not change', $result->data['note']);
        self::assertStringNotContainsString('nothing to promote', $result->data['note']);
    }

    public function testARefusalOfTheDomainKeepsItsReasonAndSaysWhatTheHouseSaw(): void
    {
        $result = $this->registry()->call('herramientas_prestar', ['fixture' => 'refuses']);

        self::assertFalse($result->success);
        $said = json_decode((string) $result->error, true);
        self::assertSame('ya_prestada', $said['error']);
        self::assertTrue($said['ran_in_house']);
        self::assertFalse($said['changed']);
        self::assertFalse($said['ok']);
    }

    public function testAStateThatIsNotAPlaceForStateIsRefusedAndNothingRuns(): void
    {
        file_put_contents($this->root . '/config/plugins.php', '<?php return [];');
        $registry = $this->registry(new DeclaringPlugin(['herramientas.agregar' => ['config/plugins.php']]));

        $result = $registry->call('herramientas_agregar', ['fixture' => 'write', 'path' => 'config/plugins.php']);

        self::assertFalse($result->success);
        self::assertStringContainsString('config/plugins.php', (string) $result->error);
        self::assertStringContainsString('nothing ran', (string) $result->error);
        self::assertSame('<?php return [];', file_get_contents($this->root . '/config/plugins.php'));
        self::assertSame([], $this->inProcess, 'not in the child, and not here either');
        self::assertDirectoryDoesNotExist($this->root . '/var/work');
        self::assertDirectoryDoesNotExist($this->root . '/var/trials', 'refused is not rehearsed');
    }

    public function testACallTheHouseCannotConfineRunsWhereItAlwaysDidOnceAPersonSaidYes(): void
    {
        $registry = $this->registry(storage: ['driver' => 'mysql', 'dsn' => 'mysql:host=10.0.0.5;dbname=app']);

        $result = $registry->call('herramientas_agregar', ['nombre' => 'Sierra']);

        self::assertTrue($result->success);
        self::assertSame([['herramientas_agregar', ['nombre' => 'Sierra']]], $this->inProcess, 'the gate asked a person before this point; here it runs as it did before trials');
        self::assertNull($registry->landedWork('herramientas_agregar', ['nombre' => 'Sierra']), 'the house saw nothing of its state, and says nothing');
    }

    public function testAuthoringStillGoesToATrial(): void
    {
        $result = $this->registry()->call('make', ['what' => 'page', 'plugin' => 'Blog']);

        self::assertTrue($result->data['ran_in_trial'] ?? false);
        self::assertArrayNotHasKey('ran_in_house', $result->data);
    }

    public function testNoTrialIsPlannedForWork(): void
    {
        $router = $this->router();
        self::assertNotNull($router->planFor(PlainPlugin::work('herramientas.agregar'), ['nombre' => 'x']), 'the control: without the work layer it is rehearsed');

        $router->runsWorkInTheHouse($this->work());

        self::assertNull($router->planFor(PlainPlugin::work('herramientas.agregar'), ['nombre' => 'x']));
        self::assertNotNull($router->planFor(self::authoring('make'), ['what' => 'page']), 'authoring is planned as before');
    }

    public function testTheHouseRemembersWhatItSawOfTheCallItJustRanOnce(): void
    {
        $registry = $this->registry();
        $result = $registry->call('herramientas_agregar', ['nombre' => 'Sierra']);

        self::assertNull($registry->landedWork('herramientas_agregar', ['nombre' => 'Otra']), 'another call');
        self::assertNull($registry->landedWork('herramientas_prestar', ['nombre' => 'Sierra']), 'another tool');
        $landed = $registry->landedWork('herramientas_agregar', ['nombre' => 'Sierra']);
        self::assertNotNull($landed);
        self::assertSame('house', $landed['environment']);
        self::assertTrue($landed['confined']);
        self::assertTrue($landed['changed']);
        self::assertSame($result->data['state'], $landed['state']);
        self::assertSame($result->data['pre_image'], $landed['pre_image']);
        self::assertDirectoryExists($this->root . '/var/work/' . $landed['pre_image']);
        self::assertNull($registry->landedWork('herramientas_agregar', ['nombre' => 'Sierra']), 'asked once: it is spent');
    }

    public function testWhatTheHouseRemembersSaysWhenTheStateDidNotChange(): void
    {
        $registry = $this->registry();
        $registry->call('herramientas_agregar', ['fixture' => 'nothing']);

        $landed = $registry->landedWork('herramientas_agregar', ['fixture' => 'nothing']);
        self::assertFalse($landed['changed'] ?? null);
        self::assertNull($landed['pre_image']);
    }

    public function testACallThatFailedIsStillRemembered(): void
    {
        $registry = $this->registry();
        $registry->call('herramientas_agregar', ['fixture' => 'add-and-fail']);

        $landed = $registry->landedWork('herramientas_agregar', ['fixture' => 'add-and-fail']);
        self::assertTrue($landed['changed'] ?? null, 'the receipt says what happened to the state, whatever the handler answered');
    }

    private static function authoring(string $name): Operation
    {
        return new Operation(name: $name, description: $name, handler: static fn (): array => ['ok' => true], mutating: true, effects: new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::Compensatable,
            Authority::WriteAsUser,
            subject: Subject::Executable,
        ));
    }

    private function work(?object $plugin = null, mixed $storage = null): HouseWork
    {
        return new HouseWork($this->root, [$plugin ?? new PlainPlugin()], $storage, new TrialRunner(bwrap: $this->bwrap));
    }

    private function router(): TrialRouter
    {
        return new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
    }

    private function registry(?object $plugin = null, mixed $storage = null): TrialAwareRegistry
    {
        $inner = new ToolRegistry(new NullLogger());
        $handler = fn (string $tool): \Closure => function (array $args) use ($tool): array {
            $this->inProcess[] = [$tool, array_diff_key($args, ['_ctx' => true])];

            return ['ok' => true];
        };
        foreach (['herramientas_agregar', 'herramientas_prestar', 'make'] as $tool) {
            $inner->register($tool, $tool, ['type' => 'object'], $handler($tool));
        }
        $operations = [PlainPlugin::work('herramientas.agregar'), PlainPlugin::work('herramientas.prestar'), self::authoring('make')];
        $router = $this->router();
        $registry = new TrialAwareRegistry($inner, $router, $operations);
        $registry->runsWorkInTheHouse($this->work($plugin, $storage), new ConfinedWork($this->root, new TrialRunner(bwrap: $this->bwrap), \dirname(__DIR__) . '/Fixtures/work-runner.php'));

        return $registry;
    }
}
