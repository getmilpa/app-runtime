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
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\AffirmativeAnswer;
use Milpa\AppRuntime\Agent\LaunchGrants;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Agent\WhatAPromotionLands;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A PROMOTION LANDS ONLY WHAT A PERSON SAID YES TO (greenhouse decisions/0628).
 *
 * Measured on the published image (evidence/1216): in `auto`, with one grant to write the plugin, the agent promoted
 * its own code into the live house three to six times per run, and no person saw it. Promoting what an agent wrote now
 * takes a person's yes on the digest of exactly those changes, in every mode. Each refusal below has its control: the
 * same trial, with the yes that names it, is admitted.
 */
final class APromotionLandsOnlyWhatAPersonSaidYesToTest extends TestCase
{
    private const WS = 'wab31ff8973a851da';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** @return iterable<string, array{AutonomyMode}> */
    public static function everyMode(): iterable
    {
        foreach (AutonomyMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    #[DataProvider('everyMode')]
    public function testNoModePromotesWhatNobodySaidYesTo(AutonomyMode $mode): void
    {
        [$root, $trial] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, $mode);

        self::assertNotNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]), 'the promotion waits for a person');
        $question = $store->load('s')?->question;
        self::assertSame('perm:sandbox:promote', $question?->id);
        $fact = json_decode((string) $question?->why, true);
        self::assertSame(['workspace' => self::WS], $fact['arguments'] ?? null, 'the arguments are untouched');
        self::assertSame(WhatAPromotionLands::digest($trial), $fact['digest'] ?? null, 'the question names the bytes');
    }

    public function testThePersonIsShownTheCodeAndWhatTheHouseRecordedOfTheTrial(): void
    {
        [$root] = $this->trialWithCode();
        $store = $this->store(AutonomyMode::Auto);
        $store->recordToolCall('s', 'edit', ['file' => 'src/Probe.php'], (string) json_encode([
            'ran_in_trial' => true, 'applied' => false, 'workspace' => self::WS,
            'output' => ['ok' => true, 'verified' => 'the controller answers 200'],
        ]));
        $gate = $this->gateOn($store, $root);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $fact = json_decode((string) $store->load('s')?->question?->why, true);

        $lands = array_column($fact['lands'] ?? [], null, 'path');
        self::assertSame('added', $lands['src/Probe.php']['status'] ?? null);
        self::assertStringContainsString("+return file_get_contents('.env');", $lands['src/Probe.php']['shown'] ?? '', 'the whole added file');
        self::assertStringContainsString("-<?php // a\n", $lands['src/A.php']['shown'] ?? '', 'the changed lines of a modified file');
        self::assertStringContainsString("+<?php // b\n", $lands['src/A.php']['shown'] ?? '');
        self::assertSame('the controller answers 200', $fact['judged']['producer']['verified'] ?? null, 'what the producer said it verified');
    }

    public function testAPersonsYesToTheseBytesAdmitsThePromotionAndTheRecordNamesThem(): void
    {
        [$root, $trial] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $store->answer('s', 'perm:sandbox:promote', AffirmativeAnswer::YES, new Principal('passkey:person', verified: true), 'test');

        $gate = $this->gateOn($store, $root);
        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]), 'the control: a person said yes to these bytes');

        $gate->recorded('sandbox_promote', ['workspace' => self::WS], '{"ok":true,"promoted":["src/Probe.php","src/A.php"]}', true);
        $promoted = array_values(array_filter($store->stream('s'), static fn ($e): bool => $e->type === 'session.trial_promoted'));
        self::assertSame(WhatAPromotionLands::digest($trial), $promoted[0]->payload['diff_digest'] ?? null, 'the record names the content, not the paths');
    }

    public function testATrialThatChangedAfterTheYesIsAskedAgain(): void
    {
        [$root, $trial] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $store->answer('s', 'perm:sandbox:promote', AffirmativeAnswer::YES, new Principal('passkey:person', verified: true), 'test');
        $seen = WhatAPromotionLands::digest($trial);

        file_put_contents($trial->copy . '/src/Probe.php', "<?php\nreturn file_get_contents('auth.json');\n");

        $gate = $this->gateOn($store, $root);
        self::assertNotNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]), 'the yes named other bytes');
        $fact = json_decode((string) $store->load('s')?->question?->why, true);
        self::assertNotSame($seen, $fact['digest'] ?? null);
        self::assertSame(WhatAPromotionLands::digest($trial), $fact['digest'] ?? null);
    }

    public function testAYesToOneTrialAdmitsNoOther(): void
    {
        [$root] = $this->trialWithCode();
        $other = TrialWorkspace::materialize($root, 'wcd31ff8973a851da', $this->stub());
        file_put_contents($other->copy . '/src/Probe.php', "<?php\nreturn file_get_contents('.env');\n");
        file_put_contents($other->copy . '/src/A.php', "<?php // b\n");
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $store->answer('s', 'perm:sandbox:promote', AffirmativeAnswer::YES, new Principal('passkey:person', verified: true), 'test');

        $gate = $this->gateOn($store, $root);
        self::assertNotNull($gate->refuse('sandbox_promote', ['workspace' => 'wcd31ff8973a851da']), 'same bytes, another trial');
        self::assertNull($this->gateOn($store, $root)->refuse('sandbox_promote', ['workspace' => self::WS]), 'the control');
    }

    /** @return iterable<string, array{string}> */
    public static function whatIsNotAPersonsYesToTheseBytes(): iterable
    {
        yield 'a yes by name for the session' => ['by-name'];
        yield 'a grant seeded at launch' => ['launch'];
        yield 'a yes nobody is named for' => ['nobody'];
        yield 'a no' => ['no'];
    }

    #[DataProvider('whatIsNotAPersonsYesToTheseBytes')]
    public function testOnlyAPersonsYesToTheseBytesAdmitsThePromotion(string $kind): void
    {
        [$root] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        match ($kind) {
            'by-name' => $store->grant('s', 'sandbox:promote'),
            'launch' => $store->answer('s', 'perm:sandbox:promote', AffirmativeAnswer::YES, new Principal('key:operator', verified: true), LaunchGrants::EXECUTOR),
            'nobody' => $store->answer('s', 'perm:sandbox:promote', AffirmativeAnswer::YES, null, 'test'),
            'no' => $store->answer('s', 'perm:sandbox:promote', 'no', new Principal('passkey:person', verified: true), 'test'),
        };

        self::assertNotNull($this->gateOn($store, $root)->refuse('sandbox_promote', ['workspace' => self::WS]));
    }

    /** @return iterable<string, array{string}> */
    public static function workspacesNobodyCanBeShown(): iterable
    {
        yield 'a trial that does not exist' => ['wffffffffffffffff'];
        yield 'a name that walks out of the trials' => ['../../src'];
        yield 'no name' => [''];
    }

    #[DataProvider('workspacesNobodyCanBeShown')]
    public function testATrialNobodyCanBeShownIsRefusedAndNothingIsAsked(string $workspace): void
    {
        [$root] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        self::assertNotNull($gate->refuse('sandbox_promote', ['workspace' => $workspace]));
        self::assertNull($store->load('s')?->question, 'a refusal, not a question');
    }

    public function testATrialThatChangesNothingIsLeftToThePromotionToRefuse(): void
    {
        $root = $this->house();
        TrialWorkspace::materialize($root, self::WS, $this->stub());
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]), 'nothing lands, nothing to ask');
        self::assertNull($store->load('s')?->question);
    }

    public function testWhatThePersonIsShownCarriesNoSecretOfTheHouse(): void
    {
        [$root, $trial] = $this->trialWithCode();
        file_put_contents($root . '/.env', "APP_SECRET=s3cr3t-value-of-the-house\n");
        file_put_contents($trial->copy . '/src/Probe.php', "<?php\nreturn 's3cr3t-value-of-the-house';\n");
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $why = (string) $store->load('s')?->question?->why;

        self::assertStringNotContainsString('s3cr3t-value-of-the-house', $why);
        self::assertStringContainsString('[secret]', $why, 'the control: the line is there, its value masked');
    }

    /** @return array{string, TrialWorkspace} */
    private function trialWithCode(): array
    {
        $root = $this->house();
        $trial = TrialWorkspace::materialize($root, self::WS, $this->stub());
        file_put_contents($trial->copy . '/src/Probe.php', "<?php\nreturn file_get_contents('.env');\n");
        file_put_contents($trial->copy . '/src/A.php', "<?php // b\n");

        return [$root, $trial];
    }

    private function house(): string
    {
        $root = sys_get_temp_dir() . '/milpa-promote-yes-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        $this->roots[] = $root;

        return $root;
    }

    private function store(AutonomyMode $mode): SessionStore
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'goal', $mode);

        return $store;
    }

    /** @return array{0: SessionToolGate, 1: SessionStore} */
    private function gate(string $root, AutonomyMode $mode): array
    {
        $store = $this->store($mode);

        return [$this->gateOn($store, $root), $store];
    }

    private function gateOn(SessionStore $store, string $root): SessionToolGate
    {
        $session = $store->load('s');
        self::assertNotNull($session);
        $bwrap = $root . '/fake-bwrap';
        file_put_contents($bwrap, "#!/bin/sh\nexit 0\n");
        chmod($bwrap, 0o755);
        $router = new TrialRouter($root, new TrialRunner(bwrap: $bwrap), $this->stub());

        return new SessionToolGate($store, $session, (new TrialOperations(new DIContainer(), $store, $root))->operations(), trialRouter: $router, houseRoot: $root);
    }

    private function stub(): string
    {
        return \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php';
    }
}
