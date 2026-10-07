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
use Milpa\AppRuntime\Agent\HouseWork;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Work in the domain is what an operation declares, and its state is where the house can confine it
 * (greenhouse decisions/0588, rules 2 and 3).
 *
 * An agent's work in the domain — lending a tool, recording a payment — does not go to a trial: a trial's copy is
 * born without the domain's state, so the rule answered against nothing (measured: `herramientas_agregar` said
 * `ok: true` and wrote nothing). It runs once, in the house, confined to the state it declares. WHAT is work the
 * operation says with what it already declares: its ceiling is `subject: data`, `externality: none`. WHERE its
 * state lives is, by default, the store of the entities its plugin registers; anything else the plugin names. And
 * the house does not believe the declaration: it enforces it, so the state can never be a place code or authority
 * lives.
 *
 * @guards an operation is work only by its declared ceiling — data, nothing leaving — and never when it is
 *         unclassified, asks for confirmation or is the house's own; its state is the store of its plugin's
 *         entities unless the plugin names another; the state is never under src/, config/, vendor/, .milpa/, the
 *         identity, the house's own records, nor reached through a link; a store on the network, or one the house
 *         cannot keep a pre-image of, asks a person
 *
 * @refuses lowering a control for what was not declared; a state that points at code, configuration or authority;
 *          a link that leads there
 *
 * @subject-in milpa/app-runtime
 */
final class WorkInTheDomainSaysWhereItsStateLivesTest extends TestCase
{
    private string $root;
    private string $bwrap;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/Fixtures/work-plugins.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-work-state-' . bin2hex(random_bytes(6));
        $this->bwrap = $this->root . '-bwrap';
        file_put_contents($this->bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($this->bwrap, 0o755);
        mkdir($this->root . '/src/Plugins/Prestamos/Entities', 0o755, true);
        mkdir($this->root . '/var', 0o755, true);
        mkdir($this->root . '/config', 0o755, true);
        file_put_contents($this->root . '/src/Plugins/Prestamos/Entities/Herramienta.php', '<?php // entity');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->bwrap);
    }

    public function testAnOperationIsWorkByWhatItDeclares(): void
    {
        self::assertTrue(HouseWork::declaresWork(self::operation('herramientas.agregar')));
        self::assertTrue(HouseWork::declaresWork(self::operation('herramientas.prestar', reversibility: Reversibility::Guaranteed)));
    }

    #[DataProvider('notWork')]
    public function testEverythingElseIsNotWork(Operation $operation): void
    {
        self::assertFalse(HouseWork::declaresWork($operation));
        self::assertNull($this->work()->planFor($operation), 'and it is planned as it always was');
    }

    /** @return iterable<string, array{0: Operation}> */
    public static function notWork(): iterable
    {
        yield 'unclassified: no profile is not harmless' => [new Operation(name: 'herramientas.agregar', description: 'x', handler: static fn (): array => [], mutating: true)];
        yield 'it writes code' => [self::operation('herramientas.agregar', subject: Subject::Executable)];
        yield 'it writes configuration' => [self::operation('herramientas.agregar', subject: Subject::Configuration)];
        yield 'its subject is unknown' => [self::operation('herramientas.agregar', subject: Subject::Unknown)];
        yield 'something leaves the house' => [self::operation('herramientas.agregar', externality: Externality::ThirdParty)];
        yield 'its externality is unknown' => [self::operation('herramientas.agregar', externality: Externality::Unknown)];
        yield 'it only reads' => [new Operation(name: 'herramientas.listar', description: 'x', handler: static fn (): array => [], effects: EffectProfile::readOnly())];
        yield 'it says it does not mutate, under a profile that writes data' => [new Operation(name: 'herramientas.agregar', description: 'x', handler: static fn (): array => [], mutating: false, effects: new EffectProfile(
            Mutation::Persistent,
            Externality::None,
            Reversibility::ManualRecovery,
            Authority::WriteAsUser,
            subject: Subject::Data,
        ))];
        yield 'it asks for confirmation itself' => [self::operation('herramientas.agregar', requiresConfirmation: true)];
        yield 'it is the house\'s own' => [self::operation('session:close')];
        yield 'it is the promotion' => [self::operation('sandbox:promote')];
    }

    public function testTheStateIsTheStoreOfTheEntitiesItsPluginRegisters(): void
    {
        file_put_contents($this->root . '/src/Plugins/Prestamos/Entities/Socio.php', '<?php // entity');

        $plan = $this->work()->planFor(self::operation('herramientas.agregar'));

        self::assertNotNull($plan);
        self::assertSame(['var/herramientas.json', 'var/socios.json'], $plan->state);
        self::assertSame('entities', $plan->source);
        self::assertNull($plan->refused);
        self::assertNull($plan->asks, 'a file the house can keep a pre-image of: nobody is asked');
        self::assertTrue($plan->confined);
    }

    public function testTheHousesStorageBlockSaysWhereThoseEntitiesLive(): void
    {
        mkdir($this->root . '/var/data', 0o755, true);

        $file = $this->work(storage: ['driver' => 'file', 'path' => $this->root . '/var/data/todo.json'])->planFor(self::operation('herramientas.agregar'));
        self::assertSame(['var/data/todo.json'], $file?->state);

        // SQLite keeps a journal beside its database: it is confined by the directory it has to itself.
        $sqlite = $this->work(storage: ['driver' => 'sqlite', 'path' => $this->root . '/var/data/app.db'])->planFor(self::operation('herramientas.agregar'));
        self::assertSame(['var/data'], $sqlite?->state);
        self::assertNull($sqlite->asks);
        self::assertTrue($sqlite->confined);
    }

    public function testAStoreOutsideTheHouseIsNotStateTheHouseCanName(): void
    {
        self::assertNull(
            $this->work(storage: ['driver' => 'file', 'path' => '/var/data/todo.json'])->planFor(self::operation('herramientas.agregar')),
            'the house does not open what is outside itself to a handler: it is rehearsed, as before',
        );
        self::assertNull($this->work(storage: ['driver' => 'file', 'path' => rtrim($this->root, '/') . '/'])->planFor(self::operation('herramientas.agregar')));
        self::assertNull($this->work(storage: ['driver' => 'file'])->planFor(self::operation('herramientas.agregar')), 'a block that names no path');
        self::assertNull($this->work(storage: 'file')->planFor(self::operation('herramientas.agregar')), 'a block that is not one');
        self::assertNull($this->work(storage: ['driver' => 'redis', 'path' => 'var/data/x'])->planFor(self::operation('herramientas.agregar')), 'a driver the house does not know how to confine');
        self::assertSame(['var/rel.json'], $this->work(storage: ['driver' => 'file', 'path' => 'var/rel.json'])->planFor(self::operation('herramientas.agregar'))?->state, 'a relative path is the house\'s');
    }

    public function testTheFirstPluginThatProvidesAnOperationIsItsPlugin(): void
    {
        mkdir($this->root . '/var/taller', 0o755, true);
        $work = new HouseWork($this->root, [new PlainPlugin(), new DeclaringPlugin(['herramientas.agregar' => ['var/taller/otro.json']])], null, new TrialRunner(bwrap: $this->bwrap));

        self::assertSame(['var/herramientas.json'], $work->planFor(self::operation('herramientas.agregar'))?->state);
    }

    public function testThePluginsAreAskedForOnlyWhenACallDeclaresWork(): void
    {
        $asked = 0;
        $work = new HouseWork($this->root, static function () use (&$asked): array {
            ++$asked;

            return [new PlainPlugin()];
        }, null, new TrialRunner(bwrap: $this->bwrap));

        $work->planFor(self::operation('herramientas.agregar', subject: Subject::Executable));
        self::assertSame(0, $asked);
        $work->planFor(self::operation('herramientas.agregar'));
        $work->planFor(self::operation('herramientas.prestar'));
        self::assertSame(1, $asked, 'once');
    }

    public function testADatabaseThatSharesItsDirectoryWithTheHouseCannotBeConfinedAndAsksAPerson(): void
    {
        $plan = $this->work(storage: ['driver' => 'sqlite', 'path' => $this->root . '/var/app.db'])->planFor(self::operation('herramientas.agregar'));

        self::assertNotNull($plan);
        self::assertFalse($plan->confined, 'var/ holds the house\'s own records: it is never opened to a handler');
        self::assertStringContainsString('a directory of its own', (string) $plan->asks);
    }

    public function testAStoreOnTheNetworkDoesNotFitAndAsksAPerson(): void
    {
        $plan = $this->work(storage: ['driver' => 'mysql', 'dsn' => 'mysql:host=10.0.0.5;dbname=app'])->planFor(self::operation('herramientas.agregar'));

        self::assertNotNull($plan);
        self::assertFalse($plan->confined);
        self::assertSame([], $plan->state);
        self::assertStringContainsString('network', (string) $plan->asks);
    }

    public function testAPluginNamesTheStateItKeepsElsewhere(): void
    {
        mkdir($this->root . '/var/taller', 0o755, true);
        $plugin = new DeclaringPlugin(['herramientas.agregar' => ['var/taller/inventario.json']]);

        $declared = $this->work($plugin)->planFor(self::operation('herramientas.agregar'));
        self::assertSame(['var/taller/inventario.json'], $declared?->state);
        self::assertSame('declared', $declared->source);

        $other = $this->work($plugin)->planFor(self::operation('herramientas.prestar'));
        self::assertSame(['var/herramientas.json'], $other?->state, 'an operation it does not name keeps the default');
    }

    #[DataProvider('notAPlaceForState')]
    public function testTheStateIsNeverWhereCodeOrAuthorityLives(string $path): void
    {
        $plan = $this->work(new DeclaringPlugin(['herramientas.agregar' => [$path]]))->planFor(self::operation('herramientas.agregar'));

        self::assertNotNull($plan, 'it is still work: it is refused, not rehearsed');
        self::assertNotNull($plan->refused, $path);
        self::assertStringContainsString($path === '' ? 'relative' : trim($path, '/'), $plan->refused);
        self::assertFalse($plan->confined);
        self::assertSame([], $plan->state);
    }

    /** @return iterable<string, array{0: string}> */
    public static function notAPlaceForState(): iterable
    {
        foreach ([
            'config/plugins.php', 'config/app.php', 'config', 'src/Plugins/Prestamos/Prestamos.php', 'src', 'vendor/autoload.php', 'vendor/milpa/datos.json', 'public/datos.json',
            '.milpa/decisions/x.md', 'storage/identity/enrollments.json', 'storage/identity/applied-trials.json', 'storage/identity',
            'var/agent-sessions.jsonl', 'var/trials/w1/copy/x.json', 'var/trials', 'var/work/a/pre/x', 'var/passkey/credentials.json',
            'var', 'storage', 'public/index.php', 'bin/coa', 'tests/x.json', 'composer.json', 'composer.lock', '.env', '.env.local', 'milpa.lock',
            'storage/plugins.json', 'resources/x.json', '.git/config', 'node_modules/x/data.json', 'var/datos\\x.json',
            'var/hook.php', 'var/data/Clase.PHP', '.', '', '/etc/passwd', '../outside.json', 'var/../config/app.php', 'var//x.json', "var/x\0.json",
        ] as $path) {
            yield ($path === '' ? '(empty)' : $path) => [$path];
        }
    }

    public function testEachRefusalSaysWhatIsWrongWithThePath(): void
    {
        self::assertSame('a state is a path relative to the house', HouseWork::notAPlaceForState($this->root, '/etc/passwd'));
        self::assertSame('a state is a path relative to the house, with no traversal', HouseWork::notAPlaceForState($this->root, 'var/../config/app.php'));
        self::assertSame('nothing under config/ is state', HouseWork::notAPlaceForState($this->root, 'config/app.json'));
        self::assertNull(HouseWork::notAPlaceForState($this->root, 'var/herramientas.json'));
        self::assertNull(HouseWork::notAPlaceForState($this->root, 'var/data'), 'a directory of its own');
        self::assertNull(HouseWork::notAPlaceForState($this->root, 'storage/datos/app.db'));
    }

    public function testADirectoryTooLargeForThePreImageAsksAPersonToo(): void
    {
        mkdir($this->root . '/var/data/deep', 0o755, true);
        file_put_contents($this->root . '/var/data/deep/app.db', str_repeat('x', HouseWork::PRE_IMAGE_LIMIT + 1));

        $plan = $this->work(storage: ['driver' => 'sqlite', 'path' => $this->root . '/var/data/app.db'])->planFor(self::operation('herramientas.agregar'));

        self::assertSame(['var/data'], $plan?->state);
        self::assertStringContainsString('pre-image', (string) $plan->asks);
        self::assertSame(HouseWork::PRE_IMAGE_LIMIT + 1, HouseWork::size($this->root, ['var/data', 'var/nunca.json']));
    }

    public function testALinkIsNeverFollowedToState(): void
    {
        symlink($this->root . '/config', $this->root . '/var/enlace');
        file_put_contents($this->root . '/config/plugins.php', '<?php return [];');
        symlink($this->root . '/config/plugins.php', $this->root . '/var/datos.json');
        mkdir($this->root . '/var/real', 0o755, true);
        symlink($this->root . '/var/real', $this->root . '/var/atajo');

        foreach (['var/enlace/datos.json', 'var/datos.json', 'var/enlace', 'var/atajo/datos.json'] as $path) {
            $plan = $this->work(new DeclaringPlugin(['herramientas.agregar' => [$path]]))->planFor(self::operation('herramientas.agregar'));
            self::assertNotNull($plan?->refused, $path);
            self::assertStringContainsString('link', $plan->refused);
        }
    }

    public function testADeclarationThatIsNotAListOfPathsIsRefused(): void
    {
        foreach ([['herramientas.agregar' => 'var/x.json'], ['herramientas.agregar' => [['var/x.json']]], ['herramientas.agregar' => []], ['herramientas.agregar' => [7]], ['herramientas.agregar' => ['el' => 'var/x.json']]] as $declaration) {
            $plan = $this->work(new DeclaringPlugin($declaration))->planFor(self::operation('herramientas.agregar'));
            self::assertNotNull($plan?->refused, json_encode($declaration) ?: '');
        }
    }

    public function testWorkWithNowhereToKeepStateIsNotWork(): void
    {
        unlink($this->root . '/src/Plugins/Prestamos/Entities/Herramienta.php');

        self::assertNull($this->work()->planFor(self::operation('herramientas.agregar')), 'no entities and nothing declared: it is rehearsed, and a rehearsal is not evidence of the house');
        self::assertNull($this->work(storage: ['driver' => 'memory'])->planFor(self::operation('herramientas.agregar')));
    }

    public function testAnOperationNoPluginOfTheHouseProvidesIsNotWork(): void
    {
        self::assertNull($this->work()->planFor(self::operation('otros.agregar')));
    }

    public function testWithoutASandboxTheCallAsksAPerson(): void
    {
        $plan = $this->work(runner: new TrialRunner(bwrap: '/nonexistent/bwrap'))->planFor(self::operation('herramientas.agregar'));

        self::assertNotNull($plan);
        self::assertFalse($plan->confined);
        self::assertStringContainsString('cannot confine', (string) $plan->asks);
        self::assertSame(['var/herramientas.json'], $plan->state, 'where the state is is still known and said');
    }

    public function testInAutoOnlyWhatTheHouseCanUndoRunsWithoutAsking(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', str_repeat('x', HouseWork::PRE_IMAGE_LIMIT + 1));

        $big = $this->work()->planFor(self::operation('herramientas.agregar'));
        self::assertTrue($big?->confined, 'it is still confined');
        self::assertStringContainsString('pre-image', (string) $big->asks);

        $guaranteed = $this->work()->planFor(self::operation('herramientas.agregar', reversibility: Reversibility::Guaranteed));
        self::assertNull($guaranteed?->asks, 'the operation guarantees its own way back');
        self::assertFalse($guaranteed->preImage, 'and the house keeps no pre-image it has no room for');
        self::assertTrue($guaranteed->confined);

        file_put_contents($this->root . '/var/herramientas.json', '[]');
        self::assertNull($this->work()->planFor(self::operation('herramientas.agregar'))?->asks);
    }

    /** The house's work layer over a fixture plugin that provides the two domain operations. */
    private function work(?object $plugin = null, mixed $storage = null, ?TrialRunner $runner = null): HouseWork
    {
        return new HouseWork($this->root, [$plugin ?? new PlainPlugin()], $storage, $runner ?? new TrialRunner(bwrap: $this->bwrap));
    }

    public static function operation(
        string $name,
        Subject $subject = Subject::Data,
        Externality $externality = Externality::None,
        Reversibility $reversibility = Reversibility::ManualRecovery,
        bool $requiresConfirmation = false,
    ): Operation {
        return new Operation(
            name: $name,
            description: 'Domain work',
            handler: static fn (): array => ['ok' => true],
            mutating: true,
            requiresConfirmation: $requiresConfirmation,
            effects: new EffectProfile(
                Mutation::Persistent,
                $externality,
                $reversibility,
                Authority::WriteAsUser,
                subject: $subject,
                rollbackContract: $reversibility === Reversibility::Guaranteed ? 'herramientas.devolver' : null,
            ),
        );
    }
}
