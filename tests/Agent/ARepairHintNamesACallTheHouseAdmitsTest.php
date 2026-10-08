<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\RecordedEdit;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE GUARDIAN OF THE REPAIR HINTS (greenhouse decisions/0571).
 *
 * A rejected `implement` ends with `summary.next`: the call the house says repairs it. Rod's second live run
 * (evidence/1099) followed that hint into `edit` with a recorded source, the house asked a person to confirm
 * it, the person said yes — and the house refused the approved call, because the hint was written for every
 * rejection while the door it names admitted three phases of four, and no test class at all.
 *
 * So this walks EVERY phase the installed DevTools can reject with — read from its source, never from a list
 * kept here — through the real trial door, for a plugin class and for its test class, for a seat and for the
 * unrestricted terminal caller, and then MAKES THE CALL THE HINT NAMES. The hint passes only if the house
 * runs that call. A phase DevTools adds later, with no judge here to provoke it, fails the suite by name.
 */
final class ARepairHintNamesACallTheHouseAdmitsTest extends TestCase
{
    private const SESSION = 's-hint';
    private const SUBJECTS = [
        'class' => ['PostController', 'src/Plugins/Blog/Controllers/PostController.php', 'App\\Plugins\\Blog\\Controllers'],
        'test' => ['PostControllerTest', 'tests/Plugins/Blog/PostControllerTest.php', 'App\\Tests\\Plugins\\Blog'],
    ];

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            exec('rm -rf ' . escapeshellarg($path));
        }
    }

    /** @return list<string> every phase the installed landing gate can put in a rejection receipt */
    private static function phases(): array
    {
        $source = (string) file_get_contents((string) (new \ReflectionClass(ImplementHandler::class))->getFileName());
        preg_match_all("/'phase' => '([a-z-]+)'/", $source, $found);
        $phases = array_values(array_unique($found[1]));
        sort($phases);

        return $phases;
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function rejections(): iterable
    {
        foreach (self::phases() as $phase) {
            foreach (array_keys(self::SUBJECTS) as $subject) {
                foreach (['seat' => ['agent:read', 'plugins.Blog:write'], 'terminal' => ['*']] as $caller => $scopes) {
                    foreach (['inline', 'finish'] as $door) {
                        yield "{$phase} · {$subject} · {$caller} · {$door}" => [$phase, $subject, $door, $scopes];
                    }
                }
            }
        }
    }

    public function testEveryPhaseOfTheInstalledGateIsOneThisGuardianCanProvoke(): void
    {
        $provoked = [];
        foreach (self::phases() as $phase) {
            $house = $this->house(['agent:read', 'plugins.Blog:write']);
            $rejection = $this->reject($house, $phase, 'class', 'inline');
            if ($rejection !== null) {
                $provoked[] = $rejection['phase'];
            }
        }
        self::assertSame(self::phases(), $provoked, 'a phase this guardian cannot provoke has a hint nobody follows; teach '
            . 'the judges in house() to reject with it');
        self::assertContains('container', $provoked, 'the installed DevTools judges construction (decisions/0541)');
    }

    /** @param list<string> $scopes */
    #[DataProvider('rejections')]
    public function testTheCallAHintNamesIsOneTheHouseRuns(string $phase, string $subject, string $door, array $scopes): void
    {
        $house = $this->house($scopes);
        $rejection = $this->reject($house, $phase, $subject, $door);
        if ($rejection === null) {
            // Not every judge sees every subject: a test class is a judge, so nothing builds or runs it.
            self::assertContains([$phase, $subject], [['container', 'test'], ['collaborators', 'test'], ['behavior', 'test']], "{$phase} did not reject a {$subject}");

            return;
        }
        self::assertSame($phase, $rejection['phase']);
        $hint = $rejection['next'];
        [$class] = self::SUBJECTS[$subject];
        $repair = [['find' => self::defect($phase), 'replace' => self::REPAIRS[$phase]]];

        if (preg_match('/with implement mode=amend and expected_sha256=([a-f0-9]{64})/', $hint, $named) === 1) {
            $followed = $house['door']->call('implement', ['plugin' => 'Blog', 'class' => $class, 'mode' => 'amend',
                'expected_sha256' => $named[1], 'edits' => $repair], $house['caller']);
        } elseif (preg_match('/with edit\b.*source\.sha256=([a-f0-9]{64})/s', $hint, $named) === 1) {
            self::assertStringContainsString('source.seq=', $hint);
            $followed = $house['door']->call('edit', ['plugin' => 'Blog', 'class' => $class,
                'source' => ['session' => self::SESSION, 'seq' => $rejection['seq'], 'sha256' => $named[1]],
                'edits' => $repair], $house['caller']);
        } elseif (str_contains($hint, 'Make the edit this refusal writes out on the plugin class — a plain edit with find and replace, and no source')) {
            // THE CURE IS NOT IN THE FILE (greenhouse evidence/1156): the hint names an edit of the PLUGIN class, a call
            // of its own that lands first. That edit is the call the house has to run.
            $followed = $house['door']->call('edit', ['plugin' => 'Blog', 'class' => 'Blog',
                'edits' => [['find' => self::BOOT, 'replace' => self::BOOT . ' the clock is registered here']]], $house['caller']);
        } elseif (preg_match('/complete corrected file with implement\b/', $hint) === 1) {
            $followed = $house['door']->call('implement', ['plugin' => 'Blog', 'class' => $class,
                'content' => str_replace(self::defect($phase), self::REPAIRS[$phase], self::proposal($phase, $subject))], $house['caller']);
        } else {
            self::fail("the hint names no call this guardian knows how to make: {$hint}");
        }

        self::assertTrue($followed->success, "the house refused the call its own hint named.\nhint: {$hint}\nrefusal: " . (string) $followed->error);
        self::assertTrue($followed->data['ran_in_trial'] ?? false);
        self::assertTrue($followed->data['output']['ok'] ?? false, 'the repaired body passes every judge');
    }

    public function testAHintForARecordedSourceIsWrittenOnlyWhenTheRecordedDoorAdmitsThatRejection(): void
    {
        $seat = $this->house(['agent:read', 'plugins.Blog:write']);
        $recorded = $this->reject($seat, 'container', 'class', 'inline');
        self::assertNotNull($recorded);
        self::assertStringContainsString('with edit', $recorded['next']);
        self::assertStringContainsString('Do not resubmit the complete file', $recorded['next']);

        // A plugin's test lands through the same gate, so its rejection is a recorded source too (evidence/1101).
        $judge = $this->reject($this->house(['agent:read', 'plugins.Blog:write']), 'static-analysis', 'test', 'inline');
        self::assertNotNull($judge);
        self::assertStringContainsString('source.seq', $judge['next']);

        // The terminal's unrestricted trial names no write set, so nothing binds the rejection to its proposal
        // and the recorded door refuses it: the hint says the call that door's refusal leaves.
        $terminal = $this->house(['*']);
        $unrecorded = $this->reject($terminal, 'container', 'class', 'inline');
        self::assertNotNull($unrecorded);
        self::assertStringNotContainsString('source.seq', $unrecorded['next']);
        self::assertStringContainsString('complete corrected file with implement', $unrecorded['next']);
        $this->expectExceptionMessage('no attributable rejection diagnostic');
        (new RecordedEdit($terminal['root'], $terminal['sessions']))->prepare(['plugin' => 'Blog', 'class' => 'PostController',
            'source' => ['session' => self::SESSION, 'seq' => $unrecorded['seq'], 'sha256' => hash('sha256', self::proposal('container', 'class'))],
            'edits' => [['find' => self::defect('container'), 'replace' => self::REPAIRS['container']]]], $terminal['caller']);
    }

    /** The line of the plugin class a cure that is not in the file edits. */
    private const BOOT = '// boot:';

    private const REPAIRS = [
        'syntax' => 'public function index(): void {}',
        'static-analysis' => '// conforms',
        'container' => '// asks for nothing',
        'collaborators' => '// is handed what it works through',
        'behavior' => '// does it',
    ];

    private static function defect(string $phase): string
    {
        return $phase === 'syntax' ? 'public function index( {}' : '// RED:' . $phase;
    }

    private static function proposal(string $phase, string $subject): string
    {
        [$class, , $namespace] = self::SUBJECTS[$subject];

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class {$class}\n{\n    "
            . self::defect($phase) . "\n}\n";
    }

    /**
     * Make one rejected call and record it the way the session gate does.
     *
     * @param array{door: TrialAwareRegistry, sessions: SessionStore, root: string, caller: ToolContext} $house
     *
     * @return array{phase: string, next: string, seq: int}|null null when no judge rejected the proposal
     */
    private function reject(array $house, string $phase, string $subject, string $door): ?array
    {
        [$class, $path] = self::SUBJECTS[$subject];
        $arguments = ['plugin' => 'Blog', 'class' => $class, 'content' => self::proposal($phase, $subject)];
        if ($door === 'finish') {
            // The staged body a promoted mode=start left beside the scaffold.
            file_put_contents($house['root'] . '/' . $path . ImplementHandler::STAGING_SUFFIX, $arguments['content']);
            $arguments = ['plugin' => 'Blog', 'class' => $class, 'mode' => 'finish'];
        }
        $result = $house['door']->call('implement', $arguments, $house['caller']);
        if ($result->success) {
            return null;
        }
        $failure = json_decode((string) $result->error, true);
        self::assertIsArray($failure, 'a judged rejection travels as its receipt: ' . (string) $result->error);
        self::assertSame('milpa.trial-authoring-failure/v1', $failure['schema']);
        $witness = null;
        foreach ($house['sessions']->stream(self::SESSION) as $event) {
            $witness = $event->type === 'session.effect_observed' ? $event->seq : $witness;
        }
        $seq = $house['sessions']->recordToolCall(
            self::SESSION,
            'implement',
            $arguments,
            (string) $result->error,
            false,
            true,
            resultChars: mb_strlen((string) $result->error),
            awaitingConfirmation: false,
            effectObservationSeq: $witness
        );

        return ['phase' => $failure['summary']['phase'], 'next' => $failure['summary']['next'], 'seq' => $seq];
    }

    /**
     * A house with one plugin, its scaffolded controller and test, and the installed landing gate behind the
     * real trial door. Only the three external judges are stand-ins: each rejects the body that carries its mark.
     *
     * @param list<string> $scopes
     *
     * @return array{door: TrialAwareRegistry, sessions: SessionStore, root: string, caller: ToolContext}
     */
    private function house(array $scopes): array
    {
        $root = sys_get_temp_dir() . '/milpa-hint-' . bin2hex(random_bytes(5));
        $this->temporary[] = $root;
        foreach (self::SUBJECTS as [$class, $path, $namespace]) {
            mkdir(\dirname($root . '/' . $path), 0o700, true);
            file_put_contents($root . '/' . $path, "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class {$class}\n{\n}\n");
        }
        // The plugin's own class: where the cure of a refusal lands when it is not in the file that was refused.
        file_put_contents($root . '/src/Plugins/Blog/Blog.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Plugins\\Blog;\n\nfinal class Blog\n{\n    " . self::BOOT . "\n}\n");
        $judges = $root . '-judges';
        $this->temporary[] = $judges;
        mkdir($judges, 0o700);
        // PHPStan's JSON for one file, and its exit; PHPUnit's summary line; the construction probe's answer.
        file_put_contents($judges . '/static.php', <<<'PHP'
            <?php
            $file = $argv[$argc - 1];
            $red = str_contains((string) file_get_contents($file), '// RED:static-analysis');
            echo json_encode(['totals' => ['errors' => 0, 'file_errors' => (int) $red], 'errors' => [], 'files' => $red
                ? [$file => ['errors' => 1, 'messages' => [['message' => 'Class App\\Nobody not found.', 'line' => 9, 'ignorable' => true, 'identifier' => 'class.notFound']]]]
                : []]), "\n";
            exit((int) $red);
            PHP);
        file_put_contents($judges . '/behavior.php', <<<'PHP'
            <?php
            $subject = dirname($argv[$argc - 1], 4) . '/src/Plugins/Blog/Controllers/PostController.php';
            $red = str_contains((string) file_get_contents($subject), '// RED:behavior');
            echo $red ? "FAILURES!\nTests: 1, Assertions: 1, Failures: 1.\n" : "OK (1 test, 1 assertion)\n";
            exit((int) $red);
            PHP);
        file_put_contents($judges . '/probe', "#!/usr/bin/env php\n" . <<<'PHP'
            <?php
            [, , $root, $class] = $argv;
            $file = $root . '/src/Plugins/Blog/Controllers/' . substr((string) strrchr($class, '\\'), 1) . '.php';
            $red = is_file($file) && str_contains((string) file_get_contents($file), '// RED:container');
            // What the probe says of an operation whose run() the house cannot hand what it works through (DevTools
            // 0.44.2, greenhouse evidence/1154): the class is built, and the entry that lists it resolves nothing.
            $unhanded = is_file($file) && str_contains((string) file_get_contents($file), '// RED:collaborators');
            echo json_encode(['booted' => true, 'routes' => is_file($file) ? ['GET /blog'] : [], 'built' => !$red,
                'error' => 'ContainerResolutionException: Cannot resolve parameter $container',
                'unresolvable' => [['parameter' => '$container', 'type' => 'Milpa\\Interfaces\\Di\\DIContainerInterface']]]
                + ($unhanded ? ['operation' => ['name' => 'blog:posts', 'handed' => false, 'unhanded' => [
                    ['type' => 'App\\Services\\Clock', 'error' => 'Service "App\\Services\\Clock" is not registered in the container.']]]] : [])), "\n";
            PHP);
        chmod($judges . '/probe', 0o700);
        $runner = $judges . '/trial-run.php';
        file_put_contents($runner, '<?php
            chdir(__DIR__);
            require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            // The probe judges only a house with an autoloader; vendor/ is outside what a trial may change.
            @mkdir(__DIR__ . "/vendor");
            touch(__DIR__ . "/vendor/autoload.php");
            $judges = ' . var_export($judges, true) . ';
            $gate = new Milpa\DevTools\Operations\ImplementHandler(
                new Milpa\DevTools\Support\RootResolver(__DIR__),
                escapeshellarg(PHP_BINARY) . " " . escapeshellarg($judges . "/static.php"),
                escapeshellarg(PHP_BINARY) . " " . escapeshellarg($judges . "/behavior.php"),
                new Milpa\DevTools\Operations\ConstructionProbe($judges . "/probe"),
            );
            $input = json_decode($argv[2], true);
            $result = $argv[1] === "edit" ? $gate->edit($input) : $gate->handle($input);
            echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
            exit(($result["ok"] ?? false) === true ? 0 : 1);
        ');
        $bwrap = $judges . '/bwrap';
        file_put_contents($bwrap, "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($bwrap, 0o700);

        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start(self::SESSION, 'build the blog', AutonomyMode::Auto);
        $inner = new ToolRegistry(new NullLogger());
        $inner->getPolicyGate()->setCallPolicy(new PluginAuthoringPolicy($root));
        $operations = [];
        foreach (['implement' => ImplementHandler::class, 'edit' => EditHandler::class] as $name => $handler) {
            $inner->register($name, $name, ['type' => 'object'], static fn (): array => ['reached_the_host' => true]);
            $operations[] = RecordedEdit::operation(new Operation(
                name: $name,
                description: $name,
                handler: [$handler, 'handle'],
                mutating: true,
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::WriteAsUser,
                    subject: Subject::Executable,
                ),
            ));
        }
        $router = new TrialRouter($root, new TrialRunner(bwrap: $bwrap), $runner);

        return ['door' => new TrialAwareRegistry($inner, $router, $operations, $sessions, self::SESSION),
            'sessions' => $sessions, 'root' => $root, 'caller' => new ToolContext(scopes: $scopes)];
    }
}
