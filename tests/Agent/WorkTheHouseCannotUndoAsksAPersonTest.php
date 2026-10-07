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
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\HouseWork;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * In `auto`, work runs alone only when the house can undo it; otherwise a person is asked, once per call
 * (greenhouse decisions/0588, rules 2, 4 and 7).
 *
 * The gates before a work call are the same as before any call: scope, intent, consent, signature. One thing is
 * added, because work no longer passes through a disposable copy: a mode that does not pause carries on alone only
 * with what the house can return from — a pre-image it keeps, or a reversal the operation guarantees. What it cannot
 * undo, and what it cannot confine — no sandbox here, a database on the network — waits for a person's yes to
 * exactly that call.
 *
 * @guards work the house can confine and undo is admitted in `auto` without a question; work it cannot undo or
 *         cannot confine asks a person in every mode, and the yes covers exactly those arguments; in `ask` work is
 *         asked about as any mutation is; a call whose state is not a place for state is refused before anyone is
 *         asked; an operation that is not work is judged as it always was
 *
 * @refuses running unconfined work in `auto` because nothing asked; spending a person's yes on a call that never
 *          runs; one yes covering another call
 *
 * @subject-in milpa/app-runtime
 */
final class WorkTheHouseCannotUndoAsksAPersonTest extends TestCase
{
    private string $root;
    private string $bwrap;
    private SessionStore $store;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/Fixtures/work-plugins.php';
    }

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/milpa-work-gate-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Prestamos/Entities', 0o755, true);
        mkdir($this->root . '/var', 0o755, true);
        mkdir($this->root . '/config', 0o755, true);
        file_put_contents($this->root . '/src/Plugins/Prestamos/Entities/Herramienta.php', '<?php // entity');
        file_put_contents($this->root . '/var/herramientas.json', '[]');
        $this->bwrap = $this->root . '-bwrap';
        file_put_contents($this->bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($this->bwrap, 0o755);
        $this->store = new SessionStore(new InMemoryEventStore());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->bwrap);
    }

    public function testInAutoWorkTheHouseCanUndoRunsWithoutAQuestion(): void
    {
        $gate = $this->gate(AutonomyMode::Auto, $this->work());

        self::assertNull($gate->refuse('herramientas_agregar', ['nombre' => 'Sierra']));
        self::assertNull($this->store->load('s')?->question, 'nobody was asked');
    }

    public function testInAutoWorkTheHouseCannotUndoAsksAPersonAndSaysWhy(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', str_repeat('x', HouseWork::PRE_IMAGE_LIMIT + 1));
        $gate = $this->gate(AutonomyMode::Auto, $this->work());

        $paused = $gate->refuse('herramientas_agregar', ['nombre' => 'Sierra']);

        self::assertNotNull($paused);
        $question = $this->store->load('s')?->question;
        self::assertSame('perm:herramientas.agregar', $question?->id);
        $why = json_decode((string) $question->why, true);
        self::assertSame(['nombre' => 'Sierra'], $why['arguments']);
        self::assertStringContainsString('pre-image', $why['because']);
    }

    public function testThePersonsYesCoversExactlyThatCall(): void
    {
        file_put_contents($this->root . '/var/herramientas.json', str_repeat('x', HouseWork::PRE_IMAGE_LIMIT + 1));
        $this->gate(AutonomyMode::Auto, $this->work())->refuse('herramientas_agregar', ['nombre' => 'Sierra']);
        $this->store->answer('s', 'perm:herramientas.agregar', 'yes', new Principal('key:PERSON', true));
        $this->store->grant('s', 'herramientas.agregar');

        $gate = $this->gate(AutonomyMode::Auto, $this->work(), start: false);

        self::assertNull($gate->refuse('herramientas_agregar', ['nombre' => 'Sierra']), 'the call the person saw');
        self::assertNotNull($gate->refuse('herramientas_agregar', ['nombre' => 'Martillo']), 'another call is another question');
        self::assertSame(['nombre' => 'Martillo'], json_decode((string) $this->store->load('s')?->question?->why, true)['arguments']);
    }

    public function testWithoutASandboxWorkAsksAPersonInAuto(): void
    {
        $gate = $this->gate(AutonomyMode::Auto, $this->work(runner: new TrialRunner(bwrap: '/nonexistent/bwrap')));

        self::assertNotNull($gate->refuse('herramientas_agregar', ['nombre' => 'Sierra']), 'it does not run with the whole house open because nothing asked');
        self::assertStringContainsString('cannot confine', json_decode((string) $this->store->load('s')?->question?->why, true)['because']);
    }

    public function testAStoreOnTheNetworkAsksAPersonInAuto(): void
    {
        $gate = $this->gate(AutonomyMode::Auto, $this->work(storage: ['driver' => 'mysql', 'dsn' => 'mysql:host=10.0.0.5;dbname=app']));

        self::assertNotNull($gate->refuse('herramientas_agregar', ['nombre' => 'Sierra']));
        self::assertStringContainsString('network', json_decode((string) $this->store->load('s')?->question?->why, true)['because']);
    }

    public function testInAskWorkIsAskedAboutAsAnyMutationIs(): void
    {
        $gate = $this->gate(AutonomyMode::Ask, $this->work());

        self::assertNotNull($gate->refuse('herramientas_agregar', ['nombre' => 'Sierra']));
        $why = json_decode((string) $this->store->load('s')?->question?->why, true);
        self::assertArrayNotHasKey('because', $why, 'the mode asked, not the work layer');
    }

    public function testACallThatWillBeRefusedIsRefusedBeforeAnyoneIsAsked(): void
    {
        $work = $this->work(new DeclaringPlugin(['herramientas.agregar' => ['config/plugins.php']]));
        foreach ([AutonomyMode::Ask, AutonomyMode::Auto] as $n => $mode) {
            $this->store = new SessionStore(new InMemoryEventStore());

            $refused = $this->gate($mode, $work)->refuse('herramientas_agregar', ['nombre' => 'Sierra']);

            self::assertStringContainsString('config/plugins.php', (string) $refused, $mode->value);
            self::assertStringContainsString('nothing ran', (string) $refused);
            self::assertNull($this->store->load('s')?->question, 'a yes is not spent on a call the house refuses');
        }
    }

    public function testWithoutTheWorkLayerTheGateIsWhatItWas(): void
    {
        $gate = $this->gate(AutonomyMode::Auto, null);

        self::assertNull($gate->refuse('herramientas_agregar', ['nombre' => 'Sierra']));
    }

    private function work(?object $plugin = null, mixed $storage = null, ?TrialRunner $runner = null): HouseWork
    {
        return new HouseWork($this->root, [$plugin ?? new PlainPlugin()], $storage, $runner ?? new TrialRunner(bwrap: $this->bwrap));
    }

    private function gate(AutonomyMode $mode, ?HouseWork $work, bool $start = true): SessionToolGate
    {
        if ($start) {
            $this->store->start('s', 'Lend the drill', $mode);
        }
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return new SessionToolGate($this->store, $session, [PlainPlugin::work('herramientas.agregar')], houseWork: $work);
    }
}
