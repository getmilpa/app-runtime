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
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A screen declared in a trial is WORK like any other (greenhouse decisions/0463):
 * declared → rehearsed → promoted → observed in the house, without collapsing those verbs.
 *
 * Measured first (evidence/0997): the store lived in `var/`, which a trial starts empty and never
 * diffs. The result said «changed nothing», carried a «served» receipt earned in the copy, promotion
 * answered «nothing to promote», and the house served 404 while the resident drifted 15 699 tokens.
 *
 * @guards the versioned store travelling into the trial and back, the receipt's world, and the
 *         promotion's own receipt
 *
 * @refuses promoting the declared screens without the authority of the operation that writes them
 *
 * @subject-in milpa/app-runtime
 */
final class ARehearsedScreenIsPromotedLikeAnyWorkTest extends TestCase
{
    private const SCREEN_SCOPE = 'milpa:component:data-table:*';

    private string $root = '';

    /** @var list<string> */
    private array $stubs = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-rehearsed-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/var', 0o777, true);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->root);
        foreach ($this->stubs as $stub) {
            @unlink($stub);
        }
    }

    public function testTheHouseScreensTravelIntoTheTrialAndCrossBackOnPromotion(): void
    {
        ScreenStore::fromConfig([], $this->root)->declare(['name' => 'old', 'columns' => [], 'rows' => []]);
        self::assertFileExists($this->root . '/config/screens.json', 'the default store is in the versioned tree');
        self::assertFileExists($this->root . '/var/screens.lock', 'its lock is machinery, kept in var/');
        self::assertFileDoesNotExist($this->root . '/config/screens.json.lock');

        $trial = TrialWorkspace::materialize($this->root, 'screens', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        $inTrial = ScreenStore::fromConfig([], $trial->copy);
        self::assertSame(['old'], $inTrial->names(), 'the trial sees what the house already declared');

        // Rehearse freely: declare, change, forget, declare again — the house does not move.
        $inTrial->declare(['name' => 'blog', 'columns' => [['key' => 'title', 'label' => 'Title']], 'rows' => []]);
        $inTrial->forget('blog');
        $inTrial->declare(['name' => 'blog', 'columns' => [['key' => 'title', 'label' => 'Title']], 'rows' => []]);
        self::assertSame(['old'], ScreenStore::fromConfig([], $this->root)->names(), 'nothing reaches the house before promotion');

        self::assertSame(['config/screens.json'], array_keys($trial->diff()), 'the diff sees the declaration, and only it');

        $result = $this->promote($trial->id, new ToolContext(principal: 'worker', channel: 'cli', scopes: [self::SCREEN_SCOPE]));
        self::assertTrue($result['ok'], json_encode($result) ?: '');
        self::assertSame(['old', 'blog'], ScreenStore::fromConfig([], $this->root)->names(), 'the house keeps its screens and gains the new one');

        // THE PROMOTION EARNS ITS OWN VERB. It cites the trial; it does not inherit «served».
        self::assertSame('promoted', $result['evidence']['predicate']);
        self::assertSame(['kind' => 'house'], $result['evidence']['environment']);
        self::assertSame(['kind' => 'trial', 'workspace' => $trial->id], $result['evidence']['from']);
        self::assertStringNotContainsString('served', (string) json_encode($result['evidence']));
    }

    public function testTheDeclaredScreensCrossOnlyWithTheAuthorityThatWritesThem(): void
    {
        $trial = TrialWorkspace::materialize($this->root, 'unauthorised', \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        ScreenStore::fromConfig([], $trial->copy)->declare(['name' => 'blog', 'columns' => [], 'rows' => []]);

        try {
            $this->promote($trial->id, new ToolContext(principal: 'worker', channel: 'cli', scopes: ['plugins.Owned:write']));
            self::fail('a plugin author may not promote the declared screens');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString("'" . self::SCREEN_SCOPE . "'", $refused->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/config/screens.json', 'nothing crossed');

        // …and the screen scope names the screens, not all of config/.
        file_put_contents($trial->copy . '/config/app.php', "<?php return ['debug' => true];\n");
        try {
            $this->promote($trial->id, new ToolContext(principal: 'worker', channel: 'cli', scopes: [self::SCREEN_SCOPE]));
            self::fail('the screen scope is not a key to the rest of config/');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString("Export 'config/app.php' is outside", $refused->getMessage());
        }
    }

    public function testAReceiptEarnedInATrialSaysWhereItWasObserved(): void
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('s-1', 'goal', AutonomyMode::Ask);
        $inner = new ToolRegistry(new NullLogger());
        $inner->register('serve', 'serves', ['type' => 'object'], static fn (): array => ['ok' => true]);
        $operation = new Operation(
            name: 'serve',
            description: 'serves a screen',
            handler: static fn (array $i): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Configuration),
        );
        $router = new TrialRouter($this->root, new TrialRunner(bwrap: $this->fakeExecBwrap()), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        $result = (new TrialAwareRegistry($inner, $router, [$operation], $sessions, 's-1'))->call('serve', []);

        self::assertTrue($result->success, (string) $result->error);
        $evidence = $result->data['output']['evidence'] ?? null;
        self::assertIsArray($evidence);
        self::assertSame('served', $evidence['predicate'], 'the producer\'s own claim is kept');
        self::assertSame(['kind' => 'trial', 'workspace' => $result->data['workspace']], $evidence['environment']);
        self::assertFalse($evidence['promoted'], 'served@trial is not served@house');

        // And the result no longer says «changed nothing» about a declaration it wrote.
        self::assertSame('added', $result->data['changed']['config/screens.json'] ?? null);
        self::assertSame('sandbox:promote', $result->data['to_apply']['operation'] ?? null);
    }

    public function testARefusalInATrialReachesTheAgentWithItsPathAndReason(): void
    {
        // Measured (evidence/1002): «invalid word» alone, four blind retries.
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('s-1', 'goal', AutonomyMode::Ask);
        $inner = new ToolRegistry(new NullLogger());
        $inner->register('refuse', 'refuses', ['type' => 'object'], static fn (): array => ['ok' => true]);
        $operation = new Operation(
            name: 'refuse',
            description: 'refuses with a reason',
            handler: static fn (array $i): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Configuration),
        );
        $router = new TrialRouter($this->root, new TrialRunner(bwrap: $this->fakeExecBwrap()), \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        $result = (new TrialAwareRegistry($inner, $router, [$operation], $sessions, 's-1'))->call('refuse', []);

        self::assertFalse($result->success);
        $said = json_decode((string) $result->error, true);
        self::assertIsArray($said, 'the refusal travels whole, not as one word');
        self::assertSame('inputs.heading', $said['path']);
        self::assertStringContainsString('$heading', $said['reason']);
        self::assertTrue($said['ran_in_trial']);
        self::assertFalse($said['applied']);
    }

    public function testAHouseWithScreensInVarKeepsThemAndRetiresTheOldFileOnItsFirstWrite(): void
    {
        file_put_contents($this->root . '/var/screens.json', (string) json_encode(['old' => ['type' => 'data-table', 'props' => []]]));

        $store = ScreenStore::fromConfig([], $this->root);
        self::assertSame(['old'], $store->names(), 'a pre-0463 house still serves what it declared');

        $store->declare(['name' => 'new', 'columns' => [], 'rows' => []]);
        self::assertSame(['old', 'new'], ScreenStore::fromConfig([], $this->root)->names());
        self::assertFileDoesNotExist($this->root . '/var/screens.json', 'the old file is migrated, not left to answer again');

        $store->forget('old');
        self::assertSame(['new'], ScreenStore::fromConfig([], $this->root)->names(), 'a forgotten screen does not come back from var/');
    }

    /** @return array<string, mixed> */
    private function promote(string $workspace, ToolContext $authority): array
    {
        $promote = (new TrialOperations(new DIContainer(), root: $this->root))->operations()[0];
        self::assertSame('sandbox:promote', $promote->name);

        return ($promote->handler)(['workspace' => $workspace], null, $authority);
    }

    private function fakeExecBwrap(): string
    {
        $path = sys_get_temp_dir() . '/fake-exec-bwrap-' . bin2hex(random_bytes(4));
        file_put_contents($path, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($path, 0o755);
        $this->stubs[] = $path;

        return $path;
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
