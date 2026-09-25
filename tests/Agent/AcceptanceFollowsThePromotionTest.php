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

use Milpa\AppRuntime\Agent\AcceptanceEvidence;
use Milpa\EventStore\Event;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance follows the promotion (greenhouse decisions/0469).
 *
 * The streams below have the shape of a real resident session (`grad3-b3`, evidence/1004): a screen
 * declared in a trial, the trial promoted, the screen observed in the house, the test run in the house.
 * Before this, the reader answered `indeterminate` over exactly that evidence.
 *
 * @guards the chain declared → promoted → tested in the house → observed in the house, each link named
 *
 * @refuses counting a test run inside the trial, or a promotion of another workspace, as house evidence
 *
 * @subject-in milpa/app-runtime
 */
final class AcceptanceFollowsThePromotionTest extends TestCase
{
    private const WS = 'wf3bc5977cb8f9414';

    private const SCOPE = ['path' => 'tests/Blog/PageTest.php', 'filter' => 'testThePage'];

    private const SCREEN = ['name' => 'blog', 'type' => 'content'];

    public function testTheWholeChainInTheHouseIsCurrentEvidence(): void
    {
        $result = $this->read($this->declared(), $this->promoted(), $this->observed(), $this->testedInTheHouse(true));

        self::assertSame('current_evidence', $result['state']);
        self::assertSame(['workspace' => self::WS, 'declaredAtSeq' => 1, 'promotedAtSeq' => 2], $result['provenance']);
        self::assertSame(['current', 'house', 4], [$result['test']['state'], $result['test']['environment'], $result['test']['toolCallSeq']]);
        self::assertSame(['current', 200], [$result['screen']['state'], $result['screen']['status']]);
        self::assertSame(['not_evaluated', 'not_recorded'], [$result['authorization'], $result['humanApproval']], 'a reading approves nothing');
    }

    public function testAMissingLinkIsNamedByTheVerbThatSuppliesIt(): void
    {
        $result = $this->read($this->declared(), $this->promoted(), $this->testedInTheHouse(true));

        self::assertSame('incomplete', $result['state']);
        self::assertSame([['operation' => 'screen:observe', 'arguments' => ['name' => 'blog']]], $result['missing']);
    }

    public function testAFailingTestInTheHouseIsAFailure(): void
    {
        self::assertSame('failed', $this->read($this->declared(), $this->promoted(), $this->observed(), $this->testedInTheHouse(false))['state']);
    }

    public function testATestInsideTheTrialIsNotHouseEvidence(): void
    {
        $inTrial = ['test', self::SCOPE, ['ran_in_trial' => true, 'ok' => true, 'failures' => 0, 'errors' => 0]];
        $result = $this->read($this->declared(), $this->promoted(), $this->observed(), $inTrial);

        self::assertSame('incomplete', $result['state']);
        self::assertSame('test', $result['missing'][0]['operation']);
    }

    public function testWhatHappenedBeforeThePromotionIsNotEvidenceOfIt(): void
    {
        // Observed and tested BEFORE the promotion: a house that did not yet have the screen.
        $result = $this->read($this->declared(), $this->observed(), $this->testedInTheHouse(true), $this->promoted());

        self::assertSame('incomplete', $result['state']);
        self::assertSame(['test', 'screen:observe'], array_column($result['missing'], 'operation'));
    }

    public function testWithoutAPromotionOfThisWorkspaceTheNativeAnswerStands(): void
    {
        $other = ['sandbox_promote', ['workspace' => 'waaaaaaaaaaaa1'], ['ok' => true, 'promoted' => ['config/screens.json'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'waaaaaaaaaaaa1', 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => 'waaaaaaaaaaaa1']]]];
        $result = $this->read($this->declared(), $other, $this->observed(), $this->testedInTheHouse(true));

        self::assertSame('indeterminate', $result['state'], 'nothing here was promoted from the requested workspace');
        self::assertArrayNotHasKey('provenance', $result);
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function declared(): array
    {
        return ['screen_declare', ['name' => 'blog', 'type' => 'post-list'], ['ran_in_trial' => true, 'applied' => false, 'workspace' => self::WS,
            'changed' => ['config/screens.json' => 'modified'], 'output' => ['ok' => true, 'screen' => 'blog', 'type' => 'content']]];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function promoted(): array
    {
        return ['sandbox_promote', ['workspace' => self::WS], ['ok' => true, 'promoted' => ['config/screens.json'],
            'evidence' => ['predicate' => 'promoted', 'subject' => self::WS, 'environment' => ['kind' => 'house'], 'from' => ['kind' => 'trial', 'workspace' => self::WS]]]];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function observed(): array
    {
        return ['screen_observe', ['name' => 'blog'], ['ok' => true, 'screen' => 'blog', 'status' => 200,
            'evidence' => ['predicate' => 'served', 'subject' => 'blog', 'environment' => ['kind' => 'house']]]];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function testedInTheHouse(bool $passed): array
    {
        return ['test', self::SCOPE, ['ok' => $passed, 'ran' => true, 'tests' => 1, 'failures' => $passed ? 0 : 1, 'errors' => 0]];
    }

    /**
     * @param array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} ...$calls
     *
     * @return array<string, mixed>
     */
    private function read(array ...$calls): array
    {
        $events = [];
        foreach ($calls as $i => [$tool, $arguments, $result]) {
            $json = (string) json_encode($result);
            $events[] = new Event('agent-session:s', 'session.tool_called', [
                'tool' => $tool, 'arguments' => $arguments, 'result' => $json, 'resultChars' => mb_strlen($json),
                'ok' => ($result['ok'] ?? true) !== false, 'awaitingConfirmation' => false,
            ], $i + 1);
        }
        $root = (string) realpath(sys_get_temp_dir());

        return AcceptanceEvidence::read($root, $events, self::WS, self::SCOPE, self::SCREEN, null);
    }
}
