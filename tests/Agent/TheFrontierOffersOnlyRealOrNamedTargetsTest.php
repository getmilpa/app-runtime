<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\SeatFrontier;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * The seat's frontier offers a grant only for a real or an intended plugin (greenhouse decisions/0496).
 *
 * In evidence/1028 the resident, told to build «a plugin named Blog», asked `make` for `BlogPlugin` — the
 * skeleton's `HelloPlugin` convention — and the panel offered «Grant plugins.BlogPlugin:write»: the one
 * button the human must not press. A refusal stays a refusal (decisions/0317); what changes is that a name
 * nobody asked for and the house does not have is never offered as authority.
 */
final class TheFrontierOffersOnlyRealOrNamedTargetsTest extends TestCase
{
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const SESSION = 'blog';
    private const GOAL = 'Build the blog this house was founded for: a plugin named Blog that serves GET /blog.';

    private string $root;
    private SessionStore $sessions;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-frontier-targets-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))
            ->record(new IdentityEnrolled(self::SEAT, ['agent:run', 'plugins:read', 'plugins:write'], 'key:' . self::HUMAN));
        $this->sessions = new SessionStore(new InMemoryEventStore());
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheInventedNameIsNotOfferedAndTheNamedOneIs(): void
    {
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
        $invented = $this->refused('BlogPlugin');
        $named = $this->refused('Blog');

        $open = $this->frontier()->openRefusals(self::SESSION);

        self::assertSame(['plugins.Blog:write'], array_column($open, 'permission'));
        self::assertSame([$named], array_column($open, 'seq'));
        self::assertNull($this->frontier()->refusal(self::SESSION, $invented), 'the grant door finds nothing to grant');
        self::assertNotNull($this->frontier()->refusal(self::SESSION, $named));
        self::assertSame(['plugins.Blog:write'], array_column($this->frontier()->sessionsFor('key:' . self::HUMAN)[0]['refusals'], 'permission'));
    }

    public function testAPluginTheHouseHasIsOfferedEvenWhenTheGoalDoesNotNameIt(): void
    {
        mkdir($this->root . '/src/Plugins/HelloPlugin', 0o777, true);
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
        $this->refused('HelloPlugin', 'controller', 'BlogController');

        self::assertSame(['plugins.HelloPlugin:write'], array_column($this->frontier()->openRefusals(self::SESSION), 'permission'));
    }

    public function testAGoalThatNamesTheSuffixedNameMakesItOfferable(): void
    {
        $this->sessions->start(self::SESSION, 'Scaffold a plugin named BlogPlugin.', by: new Principal('key:' . self::SEAT, true));
        $this->refused('BlogPlugin');

        self::assertSame(['plugins.BlogPlugin:write'], array_column($this->frontier()->openRefusals(self::SESSION), 'permission'), 'the rule is naming, not a veto on a suffix');
    }

    public function testATurnTheHumanWroteCountsAsTheStandingAsk(): void
    {
        $this->sessions->start(self::SESSION, 'Build the blog.', by: new Principal('key:' . self::SEAT, true));
        $this->sessions->recordTurn(self::SESSION, 'user', 'Call the plugin Journal.');
        $this->refused('Journal');

        self::assertSame(['plugins.Journal:write'], array_column($this->frontier()->openRefusals(self::SESSION), 'permission'));
    }

    public function testASubstringOfAWordNamesNothing(): void
    {
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
        $this->refused('log');
        $this->refused('BlogPlugins');

        self::assertSame([], $this->frontier()->openRefusals(self::SESSION), '«log» is inside «blog», not a word of the goal');
    }

    /**
     * The known cost, pinned so it is not mistaken for a guarantee: a common word of the goal used as a
     * plugin name is named by it. «a plugin named Blog» names `Plugin` — ignoring case is what lets
     * «build the blog» name `Blog`, and the two cannot be told apart without reading intent.
     */
    public function testACommonWordOfTheGoalNamesThatWordAsAPlugin(): void
    {
        $this->sessions->start(self::SESSION, self::GOAL, by: new Principal('key:' . self::SEAT, true));
        $this->refused('Plugin');

        self::assertSame(['plugins.Plugin:write'], array_column($this->frontier()->openRefusals(self::SESSION), 'permission'));
    }

    public function testTheCaseOfTheGoalDoesNotHideTheTarget(): void
    {
        $this->sessions->start(self::SESSION, 'build the blog', by: new Principal('key:' . self::SEAT, true));
        $this->refused('Blog');

        self::assertSame(['plugins.Blog:write'], array_column($this->frontier()->openRefusals(self::SESSION), 'permission'));
    }

    public function testThePolicyStillRefusesTheInventedNameAndSaysTheHouseLacksIt(): void
    {
        $policy = new PluginAuthoringPolicy($this->root);
        $missing = $policy->missing(new ToolContext('key:' . self::SEAT, 'cli', ['plugins:write']), 'make', ['what' => 'plugin', 'plugin' => 'BlogPlugin', 'name' => 'BlogPlugin']);

        self::assertNotNull($missing, 'filtering the frontier authorizes nothing');
        self::assertSame('plugins.BlogPlugin:write', $missing->permission);
        self::assertSame('BlogPlugin', $missing->plugin);
        self::assertStringContainsString("No plugin 'BlogPlugin' exists in this house yet. A new plugin takes exactly the name the task gives it.", $missing->getMessage());
    }

    public function testThePolicyDoesNotSayAnExistingPluginIsMissing(): void
    {
        mkdir($this->root . '/src/Plugins/HelloPlugin', 0o777, true);
        $missing = (new PluginAuthoringPolicy($this->root))->missing(new ToolContext('key:' . self::SEAT, 'cli', []), 'make', ['what' => 'controller', 'plugin' => 'HelloPlugin', 'name' => 'X']);

        self::assertNotNull($missing);
        self::assertStringNotContainsString('exists in this house', $missing->getMessage());
        self::assertTrue((new PluginAuthoringPolicy($this->root))->pluginExists('HelloPlugin'));
        self::assertFalse((new PluginAuthoringPolicy($this->root))->pluginExists('../HelloPlugin'), 'a path is never a plugin');
    }

    private function refused(string $plugin, string $what = 'plugin', ?string $name = null): int
    {
        return $this->sessions->recordToolCall(
            self::SESSION,
            'make',
            ['what' => $what, 'plugin' => $plugin, 'name' => $name ?? $plugin],
            "Missing required permission 'plugins.{$plugin}:write' for plugin '{$plugin}'.",
            false,
            true,
        );
    }

    private function frontier(): SeatFrontier
    {
        return SeatFrontier::forRoot($this->root, $this->sessions);
    }
}
