<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Evidence;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The closure verdict: a final answer cannot claim a completion the ledger does not back.
 *
 * Measured debt (greenhouse evidence/0442): a run ended saying «listo» while the behavioral test
 * was recorded red and todos were open. Everything here derives from RECORDED facts — nothing
 * re-scans, re-runs or asks a model — so the verdict is deterministic for a given stream.
 *
 * @internal
 */
final class ClosureVerdictTest extends TestCase
{
    private SessionStore $store;

    private InMemoryEventStore $eventos;

    protected function setUp(): void
    {
        $this->eventos = new InMemoryEventStore();
        $this->store = new SessionStore($this->eventos);
        $this->store->start('s', 'build the Tareas plugin', AutonomyMode::Auto);
    }

    public function testARedRecordedJudgeAndOpenTodosYieldUnverifiedWithNamedReasons(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'write TareasService', TodoStatus::Pending));
        // The judge's verdict as the stream records it: a producer-declared verification shape
        // whose own `ok` is false — the red is a FACT of the call, not an inference.
        $this->store->recordToolCall(
            's',
            'validate',
            ['target' => 'TareasService'],
            (string) json_encode(['ok' => false, 'checks' => ['behavior' => false]]),
        );

        $sesion = $this->store->load('s');
        self::assertNotNull($sesion);
        $closure = ClosureVerdict::derive($sesion, $this->store->facts('s'));

        self::assertFalse($closure['verified']);
        self::assertContains('1 todo open', $closure['reasons']);
        self::assertContains('judge validate recorded red for TareasService', $closure['reasons']);
    }

    public function testADoneWithoutEvidenceIsNamedNotHidden(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'ship it', TodoStatus::Done));

        $sesion = $this->store->load('s');
        self::assertNotNull($sesion);
        $closure = ClosureVerdict::derive($sesion, $this->store->facts('s'));

        self::assertFalse($closure['verified']);
        self::assertContains('todo t1 done without evidence', $closure['reasons']);
    }

    public function testASessionWhoseDonesCarryEvidenceAndNothingOpenVerifiesTrue(): void
    {
        $this->store->setTodo('s', new Todo('t1', 'ship it', TodoStatus::Pending));
        $this->store->completeTodo('s', 't1', Evidence::testPassed('e1', 'vendor/bin/phpunit --filter TareasServiceTest', 't1'));

        $sesion = $this->store->load('s');
        self::assertNotNull($sesion);
        $closure = ClosureVerdict::derive($sesion, $this->store->facts('s'));

        self::assertTrue($closure['verified']);
        self::assertSame([], $closure['reasons'], 'a clean ledger produces no reasons, not empty prose');
    }

    public function testReasonsAreBoundedTheRestIsCounted(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->store->setTodo('s', new Todo("t{$i}", 'unevidenced work', TodoStatus::Done));
        }

        $sesion = $this->store->load('s');
        self::assertNotNull($sesion);
        $closure = ClosureVerdict::derive($sesion, $this->store->facts('s'));

        self::assertFalse($closure['verified']);
        self::assertCount(16, $closure['reasons'], 'bounded: facts are named up to the cap');
        self::assertMatchesRegularExpression(
            '/and \d+ more recorded facts/',
            $closure['reasons'][15],
            'what is not named is counted, never dropped in silence',
        );
    }

    public function testRecordAppendsExactlyOneEventAndTheSessionStillFolds(): void
    {
        $closure = ['verified' => false, 'reasons' => ['1 todo open']];

        ClosureVerdict::record($this->eventos, 's', $closure);

        $delCierre = array_values(array_filter(
            $this->eventos->replay(SessionStore::PREFIX . 's'),
            static fn (object $evento): bool => $evento->type === ClosureVerdict::EVENT,
        ));
        self::assertCount(1, $delCierre, 'one final answer, one closure event');
        self::assertSame($closure, $delCierre[0]->payload);

        // The reducer ignores what it does not know: the verdict rides the stream without
        // toppling the fold — an old reader keeps loading the session untouched.
        $sesion = $this->store->load('s');
        self::assertNotNull($sesion);
        self::assertSame('build the Tareas plugin', $sesion->goal);
    }
}
