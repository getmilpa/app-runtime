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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A «no» to a promotion is terminal — the trial is dead, so it is discarded (greenhouse decisions/0071,
 * Precondition B): declining costs the same as approving, which collapses the trial on promote. Without
 * this, «no» left the workspace and the human still owed a `sandbox:discard`, an asymmetry that biased
 * the gate toward «sí».
 */
final class DiscardOnRejectTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $r) {
            self::rmrf($r);
        }
    }

    public function testAnswerNoToAPromotionDiscardsItsTrial(): void
    {
        $root = $this->root();
        $es = new InMemoryEventStore();
        $store = new SessionStore($es);
        $container = $this->container($store, $root);
        $ws = TrialWorkspace::materialize($root, 'w1', $this->runner($root));
        self::assertDirectoryExists($ws->copy);

        $this->pausedPromote($store, 'w1');
        $this->answer($container, 'no');

        self::assertNull(TrialWorkspace::open($root, 'w1'), 'a rejected promotion discards its trial');
    }

    public function testAnswerYesToAPromotionDoesNotDiscardHere(): void
    {
        // «sí» grants and the promote OPERATION collapses the trial; the reject hook must NOT also fire.
        $root = $this->root();
        $store = new SessionStore(new InMemoryEventStore());
        $container = $this->container($store, $root);
        TrialWorkspace::materialize($root, 'w1', $this->runner($root));

        $this->pausedPromote($store, 'w1');
        $this->answer($container, 'sí');

        self::assertNotNull(TrialWorkspace::open($root, 'w1'), 'yes is the promote path, not the reject discard');
    }

    public function testAnswerNoToAnOrdinaryPermissionDiscardsNothing(): void
    {
        $root = $this->root();
        $store = new SessionStore(new InMemoryEventStore());
        $container = $this->container($store, $root);
        TrialWorkspace::materialize($root, 'w1', $this->runner($root));

        $store->start('s', 'goal', AutonomyMode::Ask);
        $store->ask('s', new PendingQuestion(
            id: 'perm:config:set',
            question: '¿autorizo?',
            options: ['sí', 'no'],
            why: (string) json_encode(['operation' => 'config:set', 'arguments' => ['workspace' => 'w1']]),
            reason: 'permission',
        ));
        $this->answer($container, 'no');

        self::assertNotNull(TrialWorkspace::open($root, 'w1'), 'only a promotion reject discards a trial');
    }

    private function pausedPromote(SessionStore $store, string $workspace): void
    {
        $store->start('s', 'goal', AutonomyMode::Ask);
        $store->ask('s', new PendingQuestion(
            id: 'perm:sandbox:promote',
            question: '¿promuevo?',
            options: ['sí', 'no'],
            why: (string) json_encode(['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $workspace]]),
            reason: 'permission',
        ));
    }

    private function answer(DIContainer $container, string $answer): void
    {
        foreach ((new SessionOperations($container))->operations() as $o) {
            if ($o->name === 'agent:answer') {
                ($o->handler)(['session' => 's', 'answer' => $answer]);
            }
        }
    }

    private function container(SessionStore $store, string $root): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(SessionStore::class, $store);
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setAccessible(true);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);

        return $c;
    }

    private function runner(string $root): string
    {
        $r = $root . '/trial-run.php';
        file_put_contents($r, "<?php\n");

        return $r;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-reject-' . bin2hex(random_bytes(4));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/A.php', "<?php\n");
        $this->roots[] = $root;

        return $root;
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
