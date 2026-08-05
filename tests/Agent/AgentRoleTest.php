<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\Artifact\ArtifactCheck;
use Milpa\AppRuntime\Agent\Artifact\ArtifactRegistry;
use Milpa\AppRuntime\Agent\Role\AgentRole;
use Milpa\AppRuntime\Agent\Role\RoleRegistry;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A declared role: the prompt suggests, the rest governs.
 *
 * A `reviewer.md` that only says «NEVER modify files» leaves every mutating tool in the catalogue,
 * and this house has measured what an instruction is worth on its own: delivered 8/8, obeyed 0/8. So
 * a role carries `deny`, `first` and `produces` alongside its prose — three mechanisms that already
 * governed, now travelling together under a name.
 */
final class AgentRoleTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        $this->store = new SessionStore(new InMemoryEventStore());
        $this->store->start('parent', 'the big task', AutonomyMode::Auto);
    }

    private function reviewer(): AgentRole
    {
        return new AgentRole(
            name: 'reviewer',
            prompt: 'You are a security reviewer. You read; you do not change.',
            produces: 'review',
            deny: ['plugins_lock'],
            first: ['plan'],
            origin: 'milpa/ops',
        );
    }

    /** @return array{result: array<string, mixed>, brief: string, denied: list<string>, first: list<string>} */
    private function delegate(array $input, ?RoleRegistry $registry = null): array
    {
        $brief = '';
        $first = [];
        $spawner = new SubAgentSpawner(
            $this->store,
            'parent',
            static function (string $seen, string $childId, array $history, array $runFirst) use (&$brief, &$first): array {
                $brief = $seen;
                $first = $runFirst;

                return ['answer' => '{"verdict":"pass","reasons":[]}', 'steps' => 1];
            },
            null,
            new ArtifactRegistry(),
            new ArtifactCheck(),
            $registry ?? new RoleRegistry([$this->reviewer()]),
        );

        /** @var array<string, mixed> $result */
        $result = ($spawner->operation()->handler)($input);
        $childId = \is_string($result['sub_session'] ?? null) ? $result['sub_session'] : '';
        $child = $childId === '' ? null : $this->store->load($childId);

        return [
            'result' => $result,
            'brief' => $brief,
            'denied' => $child?->removedOptions ?? [],
            'first' => $first,
        ];
    }

    /** Its prose reaches the child, ahead of the brief: who it is before what it has to do. */
    public function testTheRolesPromptTravelsWithTheBrief(): void
    {
        $run = $this->delegate(['brief' => 'review the Client entity', 'role' => 'reviewer']);

        self::assertStringContainsString('You are a security reviewer', $run['brief']);
        self::assertStringContainsString('review the Client entity', $run['brief']);
    }

    /**
     * AND ITS RESTRICTIONS ARE EXECUTED, not requested.
     *
     * The tool leaves the child's catalogue and the obligated one is queued ahead of everything else.
     * That is the whole difference between a role and a paragraph.
     */
    public function testTheRolesDenyAndFirstAreExecutedNotAskedFor(): void
    {
        $run = $this->delegate(['brief' => 'review it', 'role' => 'reviewer']);

        self::assertContains('plugins_lock', $run['denied'], 'it left the catalogue');
        self::assertContains('plan', $run['first'], 'and the obligation runs before anything else');
    }

    /** Its artifact contract applies too, so what comes back is checked and not interpreted. */
    public function testTheRolesArtifactContractIsApplied(): void
    {
        $run = $this->delegate(['brief' => 'review it', 'role' => 'reviewer']);

        self::assertTrue($run['result']['ok']);
        self::assertSame('review', $run['result']['artifact']['kind'] ?? null);
    }

    /**
     * A DELEGATOR ADDS RESTRICTIONS AND CAN NEVER REMOVE THEM.
     *
     * GOV-08 at the call site: actors escalate, never degrade. If passing `deny: []` produced a
     * reviewer with write access, the role would be a default rather than a contract — and every
     * measured guarantee would hold only until somebody was in a hurry.
     */
    public function testADelegatorCanOnlyAddRestrictionsToARole(): void
    {
        $run = $this->delegate([
            'brief' => 'review it',
            'role' => 'reviewer',
            'deny' => ['make'],
            'first' => [],
        ]);

        self::assertContains('plugins_lock', $run['denied'], 'the role kept its own');
        self::assertContains('make', $run['denied'], 'and the caller added one more');
        self::assertContains('plan', $run['first'], 'an empty list does not clear what the role obligated');
    }

    /**
     * AN UNKNOWN NAME IS REFUSED BEFORE THE CHILD'S SESSION EXISTS, and it names the real ones.
     *
     * Whoever asks for `revisor` learns `reviewer` exists, instead of learning that something went
     * wrong. And a free phrase that used to «work» now fails — which is better than governing for
     * show.
     */
    public function testAnUnknownRoleIsRefusedWithTheListOfRealOnes(): void
    {
        $run = $this->delegate(['brief' => 'review it', 'role' => 'revisor']);

        self::assertFalse($run['result']['ok']);
        self::assertStringContainsString('revisor', (string) $run['result']['error']);
        self::assertStringContainsString('reviewer', (string) $run['result']['error']);
        self::assertArrayNotHasKey('sub_session', $run['result']);
    }

    /** Without a role nothing changes: delegating by brief alone still works exactly as it did. */
    public function testWithoutARoleTheOldDelegationIsUntouched(): void
    {
        $run = $this->delegate(['brief' => 'just look around']);

        self::assertTrue($run['result']['ok']);
        self::assertStringContainsString('just look around', $run['brief']);
        self::assertSame([], $run['denied']);
    }

    /** A role parsed from markdown carries its four fields, and the body becomes the prompt. */
    public function testARoleReadsFromMarkdownWithFrontmatter(): void
    {
        $role = RoleRegistry::parse(
            "---\nname: scout\nproduces: findings\ndeny: [make, plugins_lock]\nfirst: [plan]\n---\n\nYou look, you do not touch.",
            'fallback',
            '.milpa/agents/',
        );

        self::assertNotNull($role);
        self::assertSame('scout', $role->name);
        self::assertSame('findings', $role->produces);
        self::assertSame(['make', 'plugins_lock'], $role->deny);
        self::assertSame(['plan'], $role->first);
        self::assertStringContainsString('You look, you do not touch', $role->prompt);
    }

    /** A file without frontmatter is skipped, not fatal: a stray `.md` must not stop an app booting. */
    public function testAFileThatIsNotARoleIsSkipped(): void
    {
        self::assertNull(RoleRegistry::parse("just some notes\n", 'notes', '.milpa/agents/'));
    }

    /**
     * A ROLE WITH RESTRICTIONS AND NO PROSE IS REFUSED.
     *
     * It would still govern — and that is the danger: it would look like a specialist and behave like
     * a muzzle, and whoever delegated to it would blame the model.
     */
    public function testARoleWithoutAPromptIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AgentRole(name: 'silent', prompt: '   ', deny: ['make']);
    }

    /**
     * THE APP'S OWN ROLE WINS A NAME COLLISION — and the loser is REPORTED, never dropped silently.
     *
     * «The reviewer suddenly stopped denying make» must not be a mystery whose cause lives in a file
     * nobody thought to open.
     */
    public function testTheAppsOwnRoleWinsAndTheCollisionIsReported(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-roles-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/reviewer.md', "---\nname: reviewer\ndeny: [everything]\n---\n\nMine, not the package's.");

        $registry = new RoleRegistry([$this->reviewer()]);
        $registry->loadFrom($dir);

        unlink($dir . '/reviewer.md');
        rmdir($dir);

        self::assertSame(['everything'], $registry->get('reviewer')->deny, 'the app won');
        self::assertSame('.milpa/agents/', $registry->get('reviewer')->origin);
        self::assertSame(
            [['name' => 'reviewer', 'kept' => '.milpa/agents/', 'dropped' => 'milpa/ops']],
            $registry->collisions(),
            'and the one it displaced is named',
        );
    }

    /** The union is computed on the role itself too, not only through a delegation. */
    public function testCombiningRestrictionsIsAUnionWithoutDuplicates(): void
    {
        $combined = $this->reviewer()->combinedWith(['plugins_lock', 'make'], ['plan', 'todo']);

        self::assertSame(['plugins_lock', 'make'], $combined['deny'], 'the role\'s own is not repeated');
        self::assertSame(['plan', 'todo'], $combined['first']);
    }

    /** It serialises with its origin, which is what a catalogue shows next to the name. */
    public function testItSerialisesWithItsOrigin(): void
    {
        self::assertSame(
            ['name' => 'reviewer', 'origin' => 'milpa/ops', 'produces' => 'review', 'deny' => ['plugins_lock'], 'first' => ['plan']],
            $this->reviewer()->toArray(),
        );
    }

    /** A role without a name cannot be delegated to by name, so it is refused at declaration. */
    public function testARoleWithoutANameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AgentRole(name: '  ', prompt: 'somebody');
    }

    /** A directory that does not exist is not an error: an app with no roles of its own is normal. */
    public function testAMissingDirectoryIsNotAFailure(): void
    {
        $registry = new RoleRegistry([$this->reviewer()]);
        $registry->loadFrom('/definitely/not/here');

        self::assertSame(['reviewer'], $registry->names());
        self::assertSame([], $registry->collisions());
        self::assertTrue($registry->has('reviewer'));
        self::assertCount(1, $registry->all());
    }

    /** And an empty registry says so instead of naming an empty list of alternatives. */
    public function testAnEmptyRegistrySaysThereAreNone(): void
    {
        $this->expectExceptionMessageMatches('/\(none\)/');

        (new RoleRegistry())->get('reviewer');
    }

    /** A file whose frontmatter omits the name falls back to the file name. */
    public function testTheFileNameIsTheFallbackName(): void
    {
        $role = RoleRegistry::parse("---\nproduces: plan\n---\n\nYou plan.", 'planner', '.milpa/agents/');

        self::assertNotNull($role);
        self::assertSame('planner', $role->name);
        self::assertSame([], $role->deny, 'no deny declared is no deny, not a broken parse');
    }
}
