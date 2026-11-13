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
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\LaunchGrants;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Agent\WhatAPromotionLands;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;
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

    public function testTheQuestionSaysWhatTheYesCovers(): void
    {
        [$root, $trial] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $question = (string) $store->load('s')?->question?->question;

        self::assertStringContainsString(self::WS, $question);
        self::assertStringContainsString(WhatAPromotionLands::digest($trial), $question);
        self::assertStringContainsString('exactly these changes', $question);
        self::assertStringNotContainsString('in this session', $question, 'the yes does not cover the session');
    }

    public function testCodeWithBytesThatAreNotUtf8IsStillShown(): void
    {
        [$root, $trial] = $this->trialWithCode();
        file_put_contents($trial->copy . '/src/Probe.php', "<?php // \xff\nreturn file_get_contents('.env');\n");
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $lands = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path');

        self::assertStringContainsString("return file_get_contents('.env');", $lands['src/Probe.php']['shown'] ?? '');
        self::assertStringContainsString('not valid UTF-8', $lands['src/Probe.php']['shown'] ?? '');
    }

    public function testAnAlreadyPromotedTrialIsLeftToThePromotionsOwnAnswer(): void
    {
        [$root, $trial] = $this->trialWithCode();
        $trial->collapse();
        file_put_contents($root . '/var/trials/' . self::WS . '/promoted.json', (string) json_encode(['src/Probe.php' => 'sha']));
        self::assertNull(TrialWorkspace::open($root, self::WS), 'the copy is gone');
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]), 'the promotion answers «already promoted» and writes nothing');
        self::assertNull($store->load('s')?->question);
    }

    /**
     * THE BYTES A PERSON SAID YES TO ARE THE BYTES THAT LAND (the first independent review of decisions/0628, L2): the
     * gate admits a digest, and the promotion it hands the call to copies only a trial whose diff still has it.
     */
    public function testATrialThatChangesBetweenTheGateAndTheCopyIsNotPromoted(): void
    {
        [$root, $trial] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $store->answer('s', 'perm:sandbox:promote', AffirmativeAnswer::YES, new Principal('passkey:person', verified: true), 'test');
        $gate = $this->gateOn($store, $root);
        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]), 'the gate admitted these bytes');

        file_put_contents($trial->copy . '/src/Probe.php', "<?php\nreturn file_get_contents('auth.json');\n");
        $r = $this->promote($root, $store, $gate);

        self::assertFalse($r['ok'] ?? null);
        self::assertStringContainsString('is not what a person said yes to', (string) ($r['error'] ?? ''));
        self::assertFileDoesNotExist($root . '/src/Probe.php', 'nothing landed');
        self::assertSame("<?php // a\n", file_get_contents($root . '/src/A.php'));
    }

    public function testATrialUnchangedSinceTheGateIsPromoted(): void
    {
        [$root] = $this->trialWithCode();
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $store->answer('s', 'perm:sandbox:promote', AffirmativeAnswer::YES, new Principal('passkey:person', verified: true), 'test');
        $gate = $this->gateOn($store, $root);
        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]));

        $r = $this->promote($root, $store, $gate);

        self::assertTrue($r['ok'] ?? null, (string) ($r['error'] ?? ''));
        self::assertFileExists($root . '/src/Probe.php', 'the control: the bytes a person said yes to landed');
    }

    public function testAnEmptyTrialThatGainsAChangeAfterTheGateIsNotPromoted(): void
    {
        $root = $this->house();
        $trial = TrialWorkspace::materialize($root, self::WS, $this->stub());
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => self::WS]), 'nothing to land, nothing asked');

        file_put_contents($trial->copy . '/src/Probe.php', "<?php\nreturn file_get_contents('.env');\n");
        $r = $this->promote($root, $store, $gate);

        self::assertFalse($r['ok'] ?? null);
        self::assertStringContainsString('is not what a person said yes to', (string) ($r['error'] ?? ''));
        self::assertFileDoesNotExist($root . '/src/Probe.php');
    }

    public function testAPromotionWithNoGateIsNotAskedAboutAYes(): void
    {
        [$root] = $this->trialWithCode();
        $store = $this->store(AutonomyMode::Auto);

        $r = $this->promote($root, $store, null);

        self::assertTrue($r['ok'] ?? null, 'a person at the terminal promotes with no question of the agent\'s door: ' . ($r['error'] ?? ''));
    }

    public function testCodeWithANulByteIsShownWithTheNulMarked(): void
    {
        [$root, $trial] = $this->trialWithCode();
        file_put_contents($trial->copy . '/src/Probe.php', "<?php // \0\nreturn file_get_contents('.env');\n");
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $shown = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path')['src/Probe.php']['shown'] ?? '';

        self::assertStringContainsString("return file_get_contents('.env');", $shown, 'the code is shown, not hidden as binary');
        self::assertStringContainsString('␀', $shown);
        self::assertStringContainsString('contains NUL bytes', $shown);
        self::assertStringNotContainsString("\0", $shown);
    }

    public function testTheAgentsDoorHandsThePromotionWhatTheGateAdmitted(): void
    {
        $root = $this->house();
        $trial = TrialWorkspace::materialize($root, self::WS, $this->stub());
        [$gate] = $this->gate($root, AutonomyMode::Auto);
        $seen = [];
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('sandbox_promote', 'promote', ['type' => 'object', 'properties' => ['workspace' => ['type' => 'string']]], static function (array $args) use (&$seen): array {
            $ask = $args['_ctx']?->extra[TrialOperations::APPROVED_PROMOTION] ?? null;
            $seen[] = $ask instanceof \Closure ? $ask(self::WS) : 'no question';

            return ['ok' => true];
        });

        (new ConsentBridge($registry, [], $gate))->callTool('sandbox_promote', ['workspace' => self::WS]);
        (new ConsentBridge($registry, []))->callTool('sandbox_promote', ['workspace' => self::WS]);

        self::assertSame(WhatAPromotionLands::digest($trial), $seen[0] ?? null, 'the handler can ask what the gate admitted');
        self::assertSame('no question', $seen[1] ?? null, 'the control: with no gate of a session, nothing is handed');
    }

    public function testTheLookupIsHandedWhenASecondJudgeWrapsTheGate(): void
    {
        $root = $this->house();
        $trial = TrialWorkspace::materialize($root, self::WS, $this->stub());
        [$gate] = $this->gate($root, AutonomyMode::Auto);
        $wrapper = new class ($gate) implements ToolCallGate {
            public function __construct(private readonly ToolCallGate $inner)
            {
            }

            public function refuse(string $tool, array $arguments): ?string
            {
                return $this->inner->refuse($tool, $arguments);
            }
        };
        $seen = [];
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('sandbox_promote', 'promote', ['type' => 'object', 'properties' => ['workspace' => ['type' => 'string']]], static function (array $args) use (&$seen): array {
            $ask = $args['_ctx']?->extra[TrialOperations::APPROVED_PROMOTION] ?? null;
            $seen[] = $ask instanceof \Closure ? $ask(self::WS) : 'no question';

            return ['ok' => true];
        });

        // As AgentOperations composes it with `agent.secondOpinion`: the wrapper judges, the session gate records.
        (new ConsentBridge($registry, [], $wrapper, $gate))->callTool('sandbox_promote', ['workspace' => self::WS]);

        self::assertSame(WhatAPromotionLands::digest($trial), $seen[0] ?? null, 'the session gate is found behind the wrapper');
    }

    public function testATextFileIsShownBeforeFilesThatAreNotText(): void
    {
        [$root, $trial] = $this->trialWithCode();
        for ($i = 0; $i < 20; ++$i) {
            file_put_contents($trial->copy . "/src/0asset{$i}.bin", "\x89PNG\0" . str_repeat("\xff\xfe" . str_repeat('x', 60) . "\n", 200));
        }
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $lands = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path');

        self::assertStringContainsString("return file_get_contents('.env');", $lands['src/Probe.php']['shown'] ?? '', 'the code is shown whatever comes before it');
        self::assertLessThan(1700, mb_strlen($lands['src/0asset0.bin']['shown'] ?? ''), 'a file that is not text is shown in a smaller share');
        self::assertStringContainsString('␀', $lands['src/0asset0.bin']['shown'] ?? '', 'the control: it is still shown');
        self::assertSame(array_keys($trial->diff()), array_keys($lands), 'in the order of the diff');
    }

    /** @return iterable<string, array{string}> */
    public static function oneStrayByte(): iterable
    {
        yield 'a NUL' => ["\0"];
        yield 'a byte that is not UTF-8' => ["\xff"];
    }

    #[DataProvider('oneStrayByte')]
    public function testOneStrayByteDoesNotShrinkTheViewOfCode(string $byte): void
    {
        [$root, $trial] = $this->trialWithCode();
        file_put_contents($trial->copy . '/src/Probe.php', "<?php // {$byte}\n" . str_repeat("// padding line of ordinary code\n", 90) . "return 'the-end-marker';\n");
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $shown = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path')['src/Probe.php']['shown'] ?? '';

        self::assertStringContainsString("return 'the-end-marker';", $shown, 'the whole file is shown, in the share of text');
    }

    public function testALargeFileIsShownWithoutReadingItWholeIntoTheView(): void
    {
        [$root, $trial] = $this->trialWithCode();
        $size = 32 * 1024 * 1024;
        file_put_contents($trial->copy . '/src/0video.bin', str_repeat("\x00\xff\x10abc\n", intdiv($size, 7)) . str_repeat('z', $size % 7));
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);
        memory_reset_peak_usage();
        $base = memory_get_usage();

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $lands = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path');

        self::assertStringContainsString("{$size} bytes in the file", $lands['src/0video.bin']['shown'] ?? '');
        self::assertStringContainsString("return file_get_contents('.env');", $lands['src/Probe.php']['shown'] ?? '', 'the code is still shown');
        self::assertLessThan(3 * $size, memory_get_peak_usage() - $base, 'the view does not rewrite the whole file (the fifth review of 0628, L5-1)');
    }

    /**
     * A CHANGE IS SHOWN WHEREVER IT IS IN THE FILE (the sixth independent review of decisions/0628, M6-1): a modified
     * file larger than what one share shows is set against the house's, and the person reads the lines that changed.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function changesInLargeFiles(): iterable
    {
        $lines = array_map(static fn (int $i): string => "// an ordinary line of the welcome controller, number {$i}", range(1, 700));
        $house = implode("\n", $lines) . "\n";
        $inserted = $lines;
        array_splice($inserted, 260, 0, ['// MARKER-OF-THE-CHANGE']);
        yield 'a line inserted in a 44 KB file' => [$house, implode("\n", $inserted) . "\n", '+// MARKER-OF-THE-CHANGE'];
        yield 'a line appended to it' => [$house, $house . "// MARKER-OF-THE-CHANGE\n", '+// MARKER-OF-THE-CHANGE'];
        $guarded = $lines;
        array_splice($guarded, 300, 0, ['if (! $allowed) { // GUARD-THAT-WAS-THERE', '    return;', '}']);
        yield 'a guard deleted' => [implode("\n", $guarded) . "\n", $house, '-if (! $allowed) { // GUARD-THAT-WAS-THERE'];
        $small = implode("\n", array_slice($lines, 0, 420)) . "\n";
        yield 'a file that grew past the share' => [$small, $small . implode("\n", array_slice($lines, 420, 2)) . "\n// MARKER-OF-THE-CHANGE\n", '+// MARKER-OF-THE-CHANGE'];
    }

    #[DataProvider('changesInLargeFiles')]
    public function testAChangeInALargeFileIsShownAsTheLinesThatChanged(string $house, string $trial, string $expected): void
    {
        self::assertGreaterThan(24000, max(\strlen($house), \strlen($trial)));
        $root = $this->house();
        file_put_contents($root . '/src/Controller.php', $house);
        $workspace = TrialWorkspace::materialize($root, self::WS, $this->stub());
        file_put_contents($workspace->copy . '/src/Controller.php', $trial);
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $shown = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path')['src/Controller.php']['shown'] ?? '';

        self::assertStringContainsString($expected, $shown);
        self::assertStringStartsWith('@@ line ', $shown);
        self::assertLessThan(600, mb_strlen($shown), 'only the lines that changed, not the file');
    }

    public function testALargeTextOfManyBytesACharacterIsShownInItsWholeShare(): void
    {
        [$root, $trial] = $this->trialWithCode();
        file_put_contents($trial->copy . '/src/Probe.php', str_repeat(str_repeat('ñ', 20) . "\n", 1200));
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $shown = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path')['src/Probe.php']['shown'] ?? '';

        self::assertSame(1, (24000 % 41) % 2, 'the control: the 24,000th byte falls inside a character');
        self::assertStringNotContainsString('not valid UTF-8', $shown, 'a cut never splits a character');
        self::assertGreaterThan(5900, mb_strlen($shown), 'the share is counted in characters, not bytes');
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function theShareOfBadBytes(): iterable
    {
        yield 'NUL, one in a hundred' => ["\0", 30, true];
        yield 'NUL, over one in a hundred' => ["\0", 31, false];
        yield 'not UTF-8, one in a hundred' => ["\xff", 30, true];
        yield 'not UTF-8, over one in a hundred' => ["\xff", 31, false];
    }

    #[DataProvider('theShareOfBadBytes')]
    public function testAFileIsTextUpToOneBadByteInAHundred(string $byte, int $bad, bool $text): void
    {
        $previous = ini_get('mbstring.substitute_character');
        ini_set('mbstring.substitute_character', 'none');
        try {
            [$root, $trial] = $this->trialWithCode();
            $body = str_repeat($byte, $bad) . str_repeat('x', 3000 - $bad - 15) . 'THE-END-MARKER';
            self::assertSame(2999, \strlen($body));
            file_put_contents($trial->copy . '/src/Probe.php', $body . "\n");
            [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

            $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
            $shown = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path')['src/Probe.php']['shown'] ?? '';
        } finally {
            ini_set('mbstring.substitute_character', (string) $previous);
        }

        self::assertSame($text, str_contains($shown, 'THE-END-MARKER'), $text ? 'text: shown in its full share' : 'not text: the smaller share');
    }

    public function testTheMarkOfTheCodesOwnTextNeverGrowsTheViewPastItsBound(): void
    {
        [$root, $trial] = $this->trialWithCode();
        file_put_contents($trial->copy . '/src/Probe.php', str_repeat("[secret]\n", 2000));
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $shown = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path')['src/Probe.php']['shown'] ?? '';

        self::assertLessThan(6200, mb_strlen($shown), 'the share of one file holds with the marks in it');
        self::assertStringContainsString('(literal text of the file)', $shown);
    }

    public function testATextLikeAMaskedValueThatTheCodeCarriesIsMarkedAsTheCodes(): void
    {
        $root = $this->house();
        file_put_contents($root . '/.env', "AWS_REGION_KEY=k3y-of-the-house-0628\n");
        file_put_contents($root . '/src/Probe.php', "<?php\nreturn 'k3y-of-the-house-0628';\n");
        $trial = TrialWorkspace::materialize($root, self::WS, $this->stub());
        file_put_contents($trial->copy . '/src/Probe.php', "<?php\nreturn '[secret]';\n");
        [$gate, $store] = $this->gate($root, AutonomyMode::Auto);

        $gate->refuse('sandbox_promote', ['workspace' => self::WS]);
        $shown = array_column(json_decode((string) $store->load('s')?->question?->why, true)['lands'] ?? [], null, 'path')['src/Probe.php']['shown'] ?? '';

        self::assertStringContainsString("-return '[secret]';", $shown, 'the house\'s value is masked');
        self::assertStringContainsString("+return '[secret](literal text of the file)';", $shown, 'the code\'s own text is told apart from it');
        self::assertStringNotContainsString('k3y-of-the-house-0628', $shown);
    }

    /**
     * The promotion, called as the agent's door calls it: with the gate's answer in the call's context, or with none.
     *
     * @return array<string, mixed>
     */
    private function promote(string $root, SessionStore $store, ?SessionToolGate $gate): array
    {
        $operation = null;
        foreach ((new TrialOperations(new DIContainer(), $store, $root))->operations() as $candidate) {
            if ($candidate->name === 'sandbox:promote') {
                $operation = $candidate;
            }
        }
        self::assertNotNull($operation);
        $context = new ToolContext(
            principal: 'test',
            channel: 'cli',
            scopes: ['*'],
            extra: $gate === null ? [] : [TrialOperations::APPROVED_PROMOTION => $gate->approvedPromotion(...)],
        );

        return ($operation->handler)(['workspace' => self::WS], null, $context);
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
