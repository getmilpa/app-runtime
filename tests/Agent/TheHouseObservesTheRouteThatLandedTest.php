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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\AppRuntime\Agent\SessionProgressProbe;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The house observes the route that landed (greenhouse decisions/0494).
 *
 * Measured (evidence/1024, B4): the resident delivered a blog as a controller route — GET /blog answered
 * 200 — and the house derived no closure, because only a screen observation earned `served` in the house.
 * The promotion now observes the GET routes it landed through the house's front controller, and its
 * receipt's `observed` entries are an observation of the house under the rule of decisions/0487.
 *
 * @guards a route the promotion observed served closes a session without todos; the epilogue opens on it;
 *         a rehearsal that did not land is not a change
 *
 * @refuses a server error or no answer, a house that did not boot, a route served once and not any more,
 *          an observation that came from a trial, and a change after the observation
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseObservesTheRouteThatLandedTest extends TestCase
{
    private InMemoryEventStore $events;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->store = new SessionStore($this->events);
        $this->store->start('s', 'Build the blog a visitor reads at /blog');
    }

    public function testARouteThePromotionObservedServedIsAClosureTheHouseDerives(): void
    {
        $this->rehearse();
        $promoted = $this->promote([$this->served('/blog')]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('house_observation', $closure['scope']);
        self::assertSame(['subject' => '/blog', 'seq' => $promoted], $closure['derivedFrom']['observation'] ?? null);
        self::assertSame($promoted, $closure['derivedFrom']['lastChangeSeq'] ?? null, 'the promotion is the change, and its receipt carries the observation');
    }

    public function testAServerErrorIsNotServedAndItIsNamed(): void
    {
        $promoted = $this->promote([$this->answered('/blog', 500)]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house answered «/blog» with HTTP 500 at seq {$promoted}", $closure['reasons']);
    }

    public function testAServerErrorBesideAServedRouteStillKeepsItOpen(): void
    {
        $promoted = $this->promote([$this->served('/blog'), $this->answered('/blog/feed', 500)]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house answered «/blog/feed» with HTTP 500 at seq {$promoted}", $closure['reasons']);
    }

    public function testARequestThatDiedAnsweredNothing(): void
    {
        $promoted = $this->promote([$this->answered('/blog', null) + ['error' => 'the request process exited 255']]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house answered «/blog» with nothing at seq {$promoted}", $closure['reasons']);
    }

    public function testAHouseThatDidNotBootToBeObservedIsNamed(): void
    {
        $promoted = $this->promote([], 'the house did not boot to list its routes after the change (exit 255)');

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house could not be observed after the change at seq {$promoted}: the house did not boot to list its routes after the change (exit 255)", $closure['reasons']);
    }

    public function testALaterPromotionThatBootsAgainClearsTheFailedBoot(): void
    {
        $this->promote([], 'the house did not boot to list its routes after the change (exit 255)');
        $this->promote([$this->served('/blog')]);

        self::assertTrue($this->verdict()['verified']);
    }

    public function testAFixedRouteServesAgain(): void
    {
        $this->promote([$this->answered('/blog', 500)]);
        $this->promote([$this->served('/blog')]);

        self::assertTrue($this->verdict()['verified']);
    }

    public function testAClientErrorIsNotServedButDoesNotBlockWhatWas(): void
    {
        $promoted = $this->promote([$this->served('/blog'), $this->answered('/blog/members', 401)]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => $promoted], $closure['derivedFrom']['observation'] ?? null);
    }

    public function testOnlyClientErrorsAreNothingObserved(): void
    {
        $this->promote([$this->answered('/blog', 404)]);

        self::assertContains('nothing observed served in the house', $this->verdict()['reasons']);
    }

    public function testARouteServedOnceAndNotAnyMoreWentStale(): void
    {
        $this->promote([$this->served('/blog')]);
        $later = $this->promote([$this->answered('/blog', 404), $this->served('/about')]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house observation of «/blog» went stale: it answered HTTP 404 at seq {$later}", $closure['reasons']);
    }

    public function testAChangeAfterTheObservationUnverifiesIt(): void
    {
        $observed = $this->promote([$this->served('/blog')]);
        $changed = $this->promote([]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains("the house changed at seq {$changed} after its last observation (seq {$observed})", $closure['reasons']);
    }

    public function testARehearsalAfterTheObservationDidNotChangeTheHouse(): void
    {
        $this->promote([$this->served('/blog')]);
        $this->trialCall(applied: false);

        self::assertTrue($this->verdict()['verified'], 'a test run in a trial and not applied did not land (decisions/0494 §4)');
    }

    public function testATrialCallThatWasAppliedIsAChange(): void
    {
        $observed = $this->promote([$this->served('/blog')]);
        $applied = $this->trialCall(applied: true);

        self::assertContains("the house changed at seq {$applied} after its last observation (seq {$observed})", $this->verdict()['reasons']);
    }

    public function testAnObservationInsideATrialResultIsTheRehearsalSpeaking(): void
    {
        $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'wabc'], (string) json_encode([
            'ok' => true, 'ran_in_trial' => true, 'applied' => false, 'observed' => [$this->served('/blog')],
        ]), mutating: true);

        self::assertSame(['no positive verification evidence recorded'], $this->verdict()['reasons']);
    }

    public function testAnEntryThatDoesNotDeclareTheHouseIsNotObservedInIt(): void
    {
        $this->promote([['environment' => ['kind' => 'trial', 'workspace' => 'wabc']] + $this->served('/blog')]);

        self::assertContains('nothing observed served in the house', $this->verdict()['reasons']);
    }

    public function testTheEpilogueOpensOnTheRouteTheHouseObserved(): void
    {
        $probe = new SessionProgressProbe($this->events, 's');
        $this->rehearse();
        self::assertArrayNotHasKey('epilogue', $this->step($probe, 0) ?? []);
        $this->promote([$this->served('/blog')]);

        $opened = $this->step($probe, 1);

        self::assertSame(SessionProgressProbe::EPILOGUE_CALLS, $opened['epilogue'] ?? null);
        self::assertStringContainsString('the house observed «/blog» served in the house', $opened['notice']);
    }

    public function testCodeThatNeverLandedIsNotAnObligationOfTheHouseClosure(): void
    {
        $this->rehearse();
        $this->store->recordToolCall('s', 'make', ['what' => 'plugin', 'name' => 'BlogPlugin'], "Missing required permission 'plugins.BlogPlugin:write' for plugin 'BlogPlugin'.", mutating: true, ok: false);
        $this->promote([$this->served('/blog')]);

        $closure = $this->verdict();

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
    }

    public function testWithoutTheHouseClosureTheRehearsedArtifactIsStillNamed(): void
    {
        $this->rehearse();
        $this->promote([$this->answered('/blog', 404)]);

        self::assertContains('artifact BlogController has no current verification', $this->verdict()['reasons']);
    }

    public function testCodeWrittenStraightIntoTheHouseStillNeedsItsVerification(): void
    {
        $this->store->recordToolCall('s', 'implement', ['plugin' => 'Blog', 'class' => 'BlogController'], (string) json_encode(['ok' => true, 'file' => 'src/Plugins/Blog/BlogController.php']), mutating: true);
        $this->promote([$this->served('/blog')]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains('artifact BlogController has no current verification', $closure['reasons']);
    }

    public function testAJudgeThatRecordedRedStillKeepsItOpen(): void
    {
        $this->store->recordToolCall('s', 'make', ['what' => 'controller', 'plugin' => 'Blog', 'name' => 'BlogController'], (string) json_encode(['ok' => true, 'verify' => ['ok' => false]]), mutating: true);
        $this->promote([$this->served('/blog')]);

        $closure = $this->verdict();

        self::assertFalse($closure['verified']);
        self::assertContains('judge make recorded red for BlogController', $closure['reasons']);
    }

    /** Controller code written in a rehearsal: mutating, succeeded, and it did not land. */
    private function rehearse(): int
    {
        return $this->store->recordToolCall('s', 'implement', ['plugin' => 'Blog', 'class' => 'BlogController'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => 'wabc', 'changed' => ['src/Plugins/Blog/BlogController.php' => 'added'],
            'output' => ['ok' => true],
        ]), mutating: true);
    }

    private function trialCall(bool $applied): int
    {
        return $this->store->recordToolCall('s', 'test', ['path' => 'tests/Plugins/Blog/BlogTest.php'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => $applied, 'workspace' => 'wdef', 'changed' => [],
            'output' => ['ok' => true, 'tests' => 1, 'failures' => 0],
        ]), mutating: true);
    }

    /**
     * A promotion whose receipt carries what the house answered after it landed.
     *
     * @param list<array<string, mixed>> $observed
     */
    private function promote(array $observed, ?string $error = null): int
    {
        return $this->store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'wabc'], (string) json_encode([
            'ok' => true,
            'promoted' => ['src/Plugins/Blog/Blog.php'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'wabc', 'environment' => ['kind' => 'house'], 'paths' => ['src/Plugins/Blog/Blog.php']],
            ...($observed !== [] ? ['observed' => $observed] : []),
            ...($error !== null ? ['observation_error' => $error] : []),
        ]), mutating: true);
    }

    /** @return array<string, mixed> */
    private function served(string $path): array
    {
        return ['predicate' => 'served', 'route' => "GET {$path}", 'subject' => $path, 'status' => 200,
            'environment' => ['kind' => 'house'], 'servedAt' => $path, 'bytes' => 42, 'sha256' => str_repeat('a', 64)];
    }

    /** @return array<string, mixed> */
    private function answered(string $path, ?int $status): array
    {
        return ['route' => "GET {$path}", 'subject' => $path, 'status' => $status, 'environment' => ['kind' => 'house']];
    }

    /** @return array<string, mixed> */
    private function verdict(): array
    {
        $session = $this->store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $this->store->facts('s'), $this->store->stream('s'));
    }

    /** @return array<string, mixed>|null */
    private function step(SessionProgressProbe $probe, int $step): ?array
    {
        $this->events->append(new Event(SessionStore::PREFIX . 's', 'session.model_called', [], $this->events->nextSeq()));

        return $probe->afterStep($step);
    }
}
