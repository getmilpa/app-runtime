<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A tightened `subject ≤ configuration` grant on sandbox:promote is no longer a dead grant
 * (greenhouse decisions/0080): the gate composes the promotion's subject from the diff the workspace
 * attests, so a configuration-only promotion is admitted under the envelope while a code diff still
 * pauses — and the receipt names the workspace as the producer that lowered the axis.
 */
final class GatePromotesBySubjectOfDiffTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmrf($root);
        }
    }

    public function testAConfigurationOnlyPromotionIsAdmittedUnderTheTightenedGrantAndLeavesAReceipt(): void
    {
        [$root, $router] = $this->house();
        $ws = TrialWorkspace::materialize($root, 'w-cfg', $this->stub());
        file_put_contents($ws->copy . '/storage/plugins.json', "{\"probe\": 2}\n");

        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'promote', AutonomyMode::Ask);
        $store->grant('s', 'sandbox:promote', $this->tightened()->toArray());
        $gate = new SessionToolGate($store, $store->load('s'), [$this->promote()], trialRouter: $router);

        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => 'w-cfg']), 'configuration-only: admitted under subject≤configuration');

        $receipts = array_values(array_filter($store->stream('s'), static fn ($e): bool => $e->type === 'session.ceiling_composed'));
        self::assertCount(1, $receipts);
        $r = $receipts[0]->payload['composition']['reductions'][0];
        self::assertSame(['subject', 'executable', 'configuration', 'trial-workspace'], [$r['axis'], $r['from'], $r['to'], $r['producer']]);
        self::assertStringStartsWith('diff:', $r['provenance']);
    }

    public function testACodeDiffStillPausesUnderTheSameGrant(): void
    {
        [$root, $router] = $this->house();
        $ws = TrialWorkspace::materialize($root, 'w-code', $this->stub());
        @mkdir($ws->copy . '/src/Plugins/Probe', 0o777, true);
        file_put_contents($ws->copy . '/src/Plugins/Probe/ProbePlugin.php', "<?php\n");

        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'promote', AutonomyMode::Ask);
        $store->grant('s', 'sandbox:promote', $this->tightened()->toArray());
        $gate = new SessionToolGate($store, $store->load('s'), [$this->promote()], trialRouter: $router);

        self::assertNotNull($gate->refuse('sandbox_promote', ['workspace' => 'w-code']), 'code: the subject stays Executable, wider than the envelope — pause');
        self::assertSame([], array_filter($store->stream('s'), static fn ($e): bool => $e->type === 'session.ceiling_composed'), 'no reduction, no receipt');
    }

    public function testAPlainYesStillAdmitsEitherDiff(): void
    {
        [$root, $router] = $this->house();
        $ws = TrialWorkspace::materialize($root, 'w-code', $this->stub());
        @mkdir($ws->copy . '/src/Plugins/Probe', 0o777, true);
        file_put_contents($ws->copy . '/src/Plugins/Probe/ProbePlugin.php', "<?php\n");

        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'promote', AutonomyMode::Ask);
        $store->grant('s', 'sandbox:promote');
        $gate = new SessionToolGate($store, $store->load('s'), [$this->promote()], trialRouter: $router);

        self::assertNull($gate->refuse('sandbox_promote', ['workspace' => 'w-code']), 'a plain yes admits as before (0067 intact)');
    }

    /** @return array{string, TrialRouter} */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-trial-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/storage');
        file_put_contents($root . '/src/A.php', "<?php // a\n");
        file_put_contents($root . '/storage/plugins.json', "{\"probe\": 1}\n");
        $bwrap = $root . '/fake-bwrap';
        file_put_contents($bwrap, "#!/bin/sh\nexit 0\n");
        chmod($bwrap, 0o755);
        $this->roots[] = $root;

        return [$root, new TrialRouter($root, new TrialRunner(bwrap: $bwrap), $this->stub())];
    }

    /** The declared ceiling of sandbox:promote (decisions/0069), with a handler that never runs here. */
    private function promote(): Operation
    {
        return new Operation(
            name: 'sandbox:promote',
            description: 'promote a trial',
            handler: static fn (array $i): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::ManualRecovery,
                authority: Authority::WriteAsUser,
                subject: Subject::Executable,
            ),
        );
    }

    /** The envelope agent:answer mints for «sí, pero subject=configuration»: meet(declared, partial). */
    private function tightened(): EffectProfile
    {
        return $this->promote()->effectCeiling()->meet(EffectProfile::fromPartial(['subject' => 'configuration']));
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
