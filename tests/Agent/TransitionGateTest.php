<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TransitionGate;
use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The muscle that adjudicates STATE — step 2 of the first arrow (greenhouse evidence/0009).
 *
 * What separates this from `PrerequisiteGate` is the whole point: that gate opens when the
 * obliged tool RAN; this one opens when the declared state EXISTS, re-derived from durable state
 * on every check, with no memory to trick. A transition is not earned by executing the rite; it
 * is earned by producing the state the rite was meant to demonstrate — and a gate that cannot
 * tell those apart would be the execution proxy the 0009 battery exists to kill (case 4).
 */
final class TransitionGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-transition-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.milpa', 0o777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function writeValidFoundation(): void
    {
        file_put_contents($this->root . '/.milpa/foundation.json', json_encode([
            'schema' => 'milpa.foundation/v1',
            'domain' => 'travel-agency',
            'authorities' => ['product' => 'human', 'destructive_changes' => 'human'],
            'founded_at' => '2026-08-07T00:00:00Z',
        ], JSON_THROW_ON_ERROR));
    }

    /** A held tool is refused with the arrow's teaching while the condition is not met. */
    public function testAHeldToolIsRefusedWithTheTeaching(): void
    {
        $gate = new TransitionGate(
            static fn (string $tool): ?string => "«{$tool}» waits: the app is not founded",
            ['make', 'edit'],
        );

        self::assertSame('«make» waits: the app is not founded', $gate->reasonToWait('make'));
    }

    /** A tool outside the held list is none of this gate's business. */
    public function testAToolOutsideTheHeldListProceeds(): void
    {
        $gate = new TransitionGate(static fn (string $tool): ?string => 'blocked', ['make']);

        self::assertNull($gate->reasonToWait('plugins_list'));
        self::assertNull($gate->reasonToWait('foundation'));
    }

    /**
     * THE TABLE OPENS THE MOMENT THE STATE EXISTS — same instance, no notification, no memory.
     * This is what makes battery case 7 (rehydration) trivially true: the verdict lives on disk.
     */
    public function testTheTableOpensWhenTheStateAppearsWithNoMemoryInvolved(): void
    {
        $gate = TransitionGate::untilFounded(['make'], $this->root);

        self::assertNotNull($gate->reasonToWait('make'));

        $this->writeValidFoundation();

        self::assertNull($gate->reasonToWait('make'));
    }

    /**
     * Battery case 4, the execution-proxy falsifier: running things — any number of times —
     * opens NOTHING. Only the produced state does. A rite that aborted into a broken document
     * leaves the table closed, now teaching repair.
     */
    public function testExecutionAloneNeverOpensTheTable(): void
    {
        $gate = TransitionGate::untilFounded(['make'], $this->root);

        // «Executing» repeatedly changes nothing: there is no anota(), nothing to trick.
        self::assertNotNull($gate->reasonToWait('make'));
        self::assertNotNull($gate->reasonToWait('make'));

        // A rite that ran and aborted mid-write left defective presence — still closed.
        file_put_contents($this->root . '/.milpa/foundation.json', '{"schema": "milpa.fou');

        self::assertNotNull($gate->reasonToWait('make'));
    }

    /** Unfounded teaches the rite by name — the sentence is what makes the next call correct. */
    public function testUnfoundedTeachesTheRite(): void
    {
        $gate = TransitionGate::untilFounded(['make'], $this->root);

        $motivo = (string) $gate->reasonToWait('make');

        self::assertStringContainsString('make', $motivo);
        self::assertStringContainsString('foundation:found', $motivo);
    }

    /** Invalid teaches REPAIR and never the rite: defective presence must not invite founding. */
    public function testInvalidTeachesRepairNeverTheRite(): void
    {
        file_put_contents($this->root . '/.milpa/foundation.json', '{not json');
        $gate = TransitionGate::untilFounded(['make'], $this->root);

        $motivo = (string) $gate->reasonToWait('make');

        self::assertStringContainsStringIgnoringCase('repair', $motivo);
        self::assertStringNotContainsString('foundation:found', $motivo);
    }

    /** Indeterminate refuses to adjudicate: don't build, don't rewrite — and say why, typed. */
    public function testIndeterminateRefusesToAdjudicate(): void
    {
        file_put_contents($this->root . '/.milpa/foundation.json', json_encode([
            'schema' => 'milpa.foundation/v9', 'domain' => 'x',
        ], JSON_THROW_ON_ERROR));
        $gate = TransitionGate::untilFounded(['make'], $this->root);

        $motivo = (string) $gate->reasonToWait('make');

        self::assertStringContainsString('foundation_schema_unsupported', $motivo);
        self::assertStringNotContainsString('foundation:found', $motivo);
    }

    /**
     * The frontier derives from the SAME judge (greenhouse decisions/0006): what would be
     * refused right now is not offered right now — and the table grows the moment the state
     * earns the transition. One authority for law and presentation, never two.
     */
    public function testTheFrontierOffersExactlyWhatTheGateWouldNotRefuse(): void
    {
        $gate = TransitionGate::untilFounded(['make', 'edit'], $this->root);

        self::assertFalse($gate->offers('make'));
        self::assertFalse($gate->offers('edit'));
        self::assertTrue($gate->offers('foundation'));
        self::assertTrue($gate->offers('plugins_list'));

        $this->writeValidFoundation();

        self::assertTrue($gate->offers('make'));
        self::assertTrue($gate->offers('edit'));
    }

    /**
     * The child is born knowing (greenhouse decisions/0007): the arrow's current teaching, with
     * a neutral subject, derived from the same verdict — for prepending to a spawned errand.
     */
    public function testTheTeachingSpeaksWhileUnearnedAndFallsSilentWhenEarned(): void
    {
        $gate = TransitionGate::untilFounded(['make'], $this->root);

        $teaching = (string) $gate->teaching();

        self::assertStringContainsString('foundation:found', $teaching);
        self::assertStringNotContainsString('«make»', $teaching);

        $this->writeValidFoundation();

        self::assertNull($gate->teaching());
    }

    /** An arrow holding nothing has nothing to teach — the errand travels as it always did. */
    public function testAnEmptyArrowTeachesNothing(): void
    {
        $gate = new TransitionGate(static fn (string $tool): ?string => 'blocked', []);

        self::assertNull($gate->teaching());
    }

    /** Through the session gate: the arrow refuses the held call without pausing the session. */
    public function testThroughTheSessionGateTheArrowRefusesWithoutPausing(): void
    {
        $almacen = new SessionStore(new InMemoryEventStore());
        $almacen->start('s1', 'x', AutonomyMode::Auto);
        $sesion = $almacen->load('s1');
        self::assertNotNull($sesion);

        $compuerta = new SessionToolGate(
            $almacen,
            $sesion,
            [new Operation('make', 'Andamia', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true)],
            arrow: TransitionGate::untilFounded(['make'], $this->root),
        );

        $motivo = $compuerta->refuse('make', ['what' => 'plugin', 'plugin' => 'Agencia']);

        self::assertNotNull($motivo);
        self::assertStringContainsString('foundation:found', $motivo);
        // A closed arrow is a FACT with a teaching, not a question: nothing for a human to decide.
        self::assertNull($almacen->load('s1')?->question);
    }
}
