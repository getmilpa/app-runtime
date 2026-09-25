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

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The question a final answer put in prose, asked by the house (greenhouse decisions/0473).
 *
 * Measured on the resident: after a served trial it ended its turn with «¿Quieres que la promueva?
 * `sandbox:promote {"workspace":"wfa12122ae160bf7d"}` — se pausará para pedirte consentimiento» instead of
 * making the call. The house now asks it, through the SAME gate the call would reach.
 *
 * @guards an answer naming a living trial this session saw pauses with the promotion question and its diff
 *
 * @refuses an answer naming no trial, a trial that is gone, a trial this session never saw, and a session
 *          that already granted the promotion — the house never promotes on its own
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseAsksWhatTheAnswerNamesTest extends TestCase
{
    private const WS = 'wfa12122ae160bf7d';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testAnAnswerNamingALivingTrialItSawIsAskedAsThePromotionQuestion(): void
    {
        $root = $this->root();
        $ws = TrialWorkspace::materialize($root, self::WS, $this->stub());
        file_put_contents($ws->copy . '/config/screens.json', "{\"blog\":{}}\n");
        [$gate, $store] = $this->gate($root, sawIt: true);

        $line = $gate->askWhatTheAnswerNames('La declaración corrió en una prueba (trial `' . self::WS . '`). ¿Quieres que la promueva?');

        self::assertNotNull($line, 'the house asked');
        $question = $store->load('s-1')?->question;
        self::assertSame('perm:sandbox:promote', $question?->id, 'the permission question the call itself raises');
        $fact = json_decode((string) $question?->why, true);
        self::assertSame(['workspace' => self::WS], $fact['arguments'] ?? null);
        self::assertSame('added', $fact['cambios']['config/screens.json'] ?? null, 'with the diff the human decides on');
    }

    public function testNothingIsAskedWithoutALivingTrialThisSessionSaw(): void
    {
        $root = $this->root();
        TrialWorkspace::materialize($root, self::WS, $this->stub());

        [$gate, $store] = $this->gate($root, sawIt: true);
        self::assertNull($gate->askWhatTheAnswerNames('Done: the page is served in the house.'), 'no trial named');
        self::assertNull($gate->askWhatTheAnswerNames('Promote wabcdef0123456789? '), 'a trial that does not exist');

        [$gate, $store] = $this->gate($root, sawIt: false);
        self::assertNull($gate->askWhatTheAnswerNames('Promote ' . self::WS . '?'), 'a trial this session never saw');
        self::assertNull($store->load('s-1')?->question);
    }

    public function testATrialItSawThatIsGoneIsNotAsked(): void
    {
        // Seen, then promoted or discarded: the trial no longer exists, and there is nothing to promote.
        $root = $this->root();
        TrialWorkspace::materialize($root, self::WS, $this->stub());
        [$gate, $store] = $this->gate($root, sawIt: true);
        self::rmrf($root . '/var/trials/' . self::WS);

        self::assertNull($gate->askWhatTheAnswerNames('Promote ' . self::WS . '?'));
        self::assertNull($store->load('s-1')?->question);
    }

    public function testAGateThatCannotJudgeThePromotionHasAskedNothing(): void
    {
        $root = $this->root();
        TrialWorkspace::materialize($root, self::WS, $this->stub());
        [$gate, $store] = $this->gate($root, sawIt: true, withTrialOperations: false);

        self::assertNull($gate->askWhatTheAnswerNames('Promote ' . self::WS . '?'), 'a refusal is not a question');
        self::assertNull($store->load('s-1')?->question);
    }

    public function testAGrantedPromotionIsNotAskedAgainAndNeverRunByTheHouse(): void
    {
        $root = $this->root();
        TrialWorkspace::materialize($root, self::WS, $this->stub());
        [$gate, $store] = $this->gate($root, sawIt: true, granted: true);

        self::assertNull($gate->askWhatTheAnswerNames('Promote ' . self::WS . '?'));
        self::assertNull($store->load('s-1')?->question);
        self::assertDirectoryExists($root . '/var/trials/' . self::WS, 'the trial is still there: the house did not promote it');
    }

    /** @return array{0: SessionToolGate, 1: SessionStore} */
    private function gate(string $root, bool $sawIt, bool $granted = false, bool $withTrialOperations = true): array
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s-1', 'goal', AutonomyMode::Ask);
        if ($sawIt) {
            $store->recordToolCall('s-1', 'screen_declare', ['name' => 'blog'], '{"ran_in_trial":true,"workspace":"' . self::WS . '"}');
        }
        if ($granted) {
            $store->grant('s-1', 'sandbox:promote');
        }
        $session = $store->load('s-1');
        self::assertNotNull($session);

        $bwrap = $root . '/fake-bwrap';
        file_put_contents($bwrap, "#!/bin/sh\nexit 0\n");
        chmod($bwrap, 0o755);
        $router = new TrialRouter($root, new TrialRunner(bwrap: $bwrap), $this->stub());
        $ops = $withTrialOperations ? (new TrialOperations(new DIContainer(), $store, $root))->operations() : [];

        return [new SessionToolGate($store, $session, $ops, trialRouter: $router), $store];
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-prose-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/config');
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        $this->roots[] = $root;

        return $root;
    }

    private function stub(): string
    {
        return \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php';
    }

    private static function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
