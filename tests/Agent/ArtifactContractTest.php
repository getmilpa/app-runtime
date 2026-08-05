<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\Artifact\ArtifactCheck;
use Milpa\AppRuntime\Agent\Artifact\ArtifactContract;
use Milpa\AppRuntime\Agent\Artifact\ArtifactRegistry;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * What one agent hands the next is a CHECKED shape, not a paragraph anybody trusts.
 *
 * This is the piece the workflow rests on. Q-P19-R/S measured what happens when the system asks for
 * a promise it cannot verify: all eight delegators wrote reasonable, unreachable criteria and every
 * metric got worse. A contract is the opposite move — the system does not ask the child to promise,
 * it declares a shape and checks it.
 */
final class ArtifactContractTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('parent', 'the big task', AutonomyMode::Auto);
    }

    /** @param list<array{string, int}> $answers each turn's answer and step count, in order */
    private function spawner(array $answers, ?ArtifactRegistry $registry = null): SubAgentSpawner
    {
        $turn = 0;

        return new SubAgentSpawner(
            $this->store,
            'parent',
            static function () use (&$turn, $answers): array {
                $reply = $answers[$turn] ?? ['', 0];
                ++$turn;

                return ['answer' => $reply[0], 'steps' => $reply[1]];
            },
            null,
            $registry ?? new ArtifactRegistry(),
            new ArtifactCheck(),
        );
    }

    /** @return array<string, mixed> */
    private function delegate(SubAgentSpawner $spawner, string $produces): array
    {
        /** @var array<string, mixed> $result */
        $result = ($spawner->operation()->handler)(['brief' => 'go and look', 'produces' => $produces]);

        return $result;
    }

    /**
     * THE SHAPE REACHES THE CHILD FROM THE SAME DECLARATION THAT LATER JUDGES IT.
     *
     * Writing the expected format into the brief by hand and validating against a schema elsewhere
     * would be two sources of one truth. They agree until someone edits one — and from then on the
     * child is told to produce A and judged against B, which reads like the model failing.
     */
    public function testTheChildIsToldTheExactShapeItWillBeJudgedBy(): void
    {
        $seen = null;
        $spawner = new SubAgentSpawner(
            $this->store,
            'parent',
            static function (string $brief) use (&$seen): array {
                $seen = $brief;

                return ['answer' => '{"goal":"x","steps":[{"what":"read"}]}', 'steps' => 1];
            },
        );

        ($spawner->operation()->handler)(['brief' => 'plan the work', 'produces' => 'plan']);

        self::assertIsString($seen);
        self::assertStringContainsString('«plan»', $seen);
        self::assertStringContainsString('steps', $seen, 'the field names travel, not a paraphrase');
        self::assertStringContainsString('required', $seen);
    }

    /** A well-formed artifact comes back parsed, so the parent reads fields instead of interpreting prose. */
    public function testAValidArtifactArrivesAsFieldsAndNotAsText(): void
    {
        $result = $this->delegate(
            $this->spawner([['{"goal":"ship it","steps":[{"what":"read","touches":["a.php"]}]}', 2]]),
            'plan',
        );

        self::assertTrue($result['ok']);
        self::assertSame('plan', $result['artifact']['kind']);
        self::assertSame('ship it', $result['artifact']['payload']['goal']);
        self::assertArrayNotHasKey('artifact_retried', $result);
    }

    /**
     * A MALFORMED RETURN GOES BACK TO THE CHILD, NOT UP TO THE PARENT — and this is the whole point.
     *
     * The child is the only one that can fix its own output and it is still alive. Handing the parent
     * something malformed makes the parent's next move a guess; handing the parent an error throws
     * the delegation away. Only this branch keeps the work.
     */
    public function testAChildThatAnswersInProseIsGivenTheDiscrepancyAndTriesAgain(): void
    {
        $result = $this->delegate(
            $this->spawner([
                ['I looked at three files and I think we should start with the router.', 3],
                ['{"goal":"start with the router","steps":[{"what":"read the router"}]}', 2],
            ]),
            'plan',
        );

        self::assertTrue($result['ok'], 'the second attempt satisfied the contract');
        self::assertTrue($result['artifact_retried']);
        self::assertSame('start with the router', $result['artifact']['payload']['goal']);
        self::assertSame(5, $result['steps'], 'the retry costs what it cost — the tree pays for it');

        // Its OWN session survived the correction: the retry is another turn of the same child, not
        // a fresh delegation that would have thrown the first attempt away.
        $child = $this->store->load((string) $result['sub_session']);
        self::assertNotNull($child);
        self::assertSame('parent', $child->parentId);
    }

    /**
     * THE CORRECTION ARRIVES WITH THE CHILD'S OWN WINDOW — and the stub has to LOOK at it.
     *
     * The retry used to pass an empty history while the discrepancy message told the child, word for
     * word, «keep the work you already did — this is about the shape, not about what you found». It
     * asked the child to keep something that had just been taken out of its sight.
     *
     * With luck it reinvented something similar. Without luck it returned JSON with the right shape
     * and hollow content — WHICH PASSES VALIDATION. A conforming, hollow artifact is worse than a
     * malformed one: nobody looks at it twice.
     *
     * And the suite did not see it, because every other stub here returns the right thing regardless
     * of what it receives. **A stub that ignores its arguments cannot refute anything about them.**
     * This one inspects.
     */
    public function testTheCorrectionReachesTheChildWithItsOwnWorkStillInSight(): void
    {
        $historyOnRetry = null;
        $turn = 0;
        $spawner = new SubAgentSpawner(
            $this->store,
            'parent',
            static function (string $brief, string $childId, array $history) use (&$turn, &$historyOnRetry): array {
                ++$turn;
                if ($turn === 1) {
                    return ['answer' => 'I read three files and the router is the place to start.', 'steps' => 3];
                }
                $historyOnRetry = $history;

                return ['answer' => '{"goal":"start with the router","steps":[{"what":"read it"}]}', 'steps' => 2];
            },
        );

        ($spawner->operation()->handler)(['brief' => 'plan the work', 'produces' => 'plan']);

        self::assertIsArray($historyOnRetry);
        self::assertNotSame([], $historyOnRetry, 'the retry is not a fresh start');

        $seen = json_encode($historyOnRetry, JSON_UNESCAPED_UNICODE);
        self::assertIsString($seen);
        self::assertStringContainsString('plan the work', $seen, 'its original brief is still there');
        self::assertStringContainsString('the router is the place to start', $seen, 'and so is what it found');
    }

    /**
     * AND IF IT STILL DOES NOT COMPLY, IT IS NOT RETURNED AS IF IT HAD.
     *
     * The child's work is not thrown away — it travels as `report` — but the label tells the truth
     * about its shape. An artifact that failed and arrives marked `ok` teaches the parent to read
     * fields that are not there.
     */
    public function testAChildThatNeverCompliesIsReportedAsFailedWithItsWorkIntact(): void
    {
        $result = $this->delegate(
            $this->spawner([['still prose', 1], ['prose again, sorry', 1]]),
            'plan',
        );

        self::assertFalse($result['ok']);
        self::assertSame('plan', $result['artifact_failed']);
        self::assertArrayNotHasKey('artifact', $result);
        self::assertStringContainsString('prose again', (string) $result['report'], 'its work survives');
    }

    /**
     * ONLY TWO ATTEMPTS. Two tell «wrong envelope» apart from «cannot do this shape»; from the third
     * on, the tree would be spending its budget on formatting instead of on work.
     */
    public function testItDoesNotKeepAskingForever(): void
    {
        $calls = 0;
        $spawner = new SubAgentSpawner(
            $this->store,
            'parent',
            static function () use (&$calls): array {
                ++$calls;

                return ['answer' => 'never json', 'steps' => 1];
            },
        );

        ($spawner->operation()->handler)(['brief' => 'go', 'produces' => 'plan']);

        self::assertSame(2, $calls);
    }

    /**
     * AN UNKNOWN KIND IS REFUSED BEFORE THE CHILD'S SESSION IS EVEN OPENED.
     *
     * Opening it and finding out on return would spend a whole model turn learning something that
     * was already known here. And the refusal names the ones that exist, so a misspelling is a
     * correction rather than a dead end.
     */
    public function testAnUnknownKindIsRefusedByNameWithTheListOfRealOnes(): void
    {
        $result = $this->delegate($this->spawner([['{}', 1]]), 'finding');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('finding', (string) $result['error']);
        self::assertStringContainsString('findings', (string) $result['error'], 'it says which ones exist');
        // No session was opened: a refusal that had already cost a child is not a refusal at the door.
        self::assertArrayNotHasKey('sub_session', $result);
    }

    /** Without `produces` nothing changes: the contract is opt-in, and prose delegation still works. */
    public function testWithoutAContractTheOldProseDelegationIsUntouched(): void
    {
        /** @var array<string, mixed> $result */
        $result = ($this->spawner([['a paragraph of findings', 2]])->operation()->handler)(['brief' => 'look around']);

        self::assertTrue($result['ok']);
        self::assertSame('a paragraph of findings', $result['report']);
        self::assertArrayNotHasKey('artifact', $result);
    }

    /** The four shipped kinds are offered by name in the tool schema, so a model can only pick real ones. */
    public function testTheCatalogueOffersOnlyKindsThatExist(): void
    {
        $schema = $this->spawner([])->operation()->inputSchema;

        self::assertSame(
            ['changes', 'findings', 'plan', 'review'],
            $schema['properties']['produces']['enum'],
        );
    }

    /** A contract whose payload is not an object is refused at declaration: a bare string is prose again. */
    public function testAContractMustDescribeAnObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ArtifactContract('loose', 'x', ['type' => 'string']);
    }
}
