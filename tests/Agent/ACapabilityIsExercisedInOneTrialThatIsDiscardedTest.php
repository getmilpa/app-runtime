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

use Milpa\AppRuntime\Agent\CapabilityExercise;
use Milpa\AppRuntime\Agent\TrialInputAttempt;
use Milpa\AppRuntime\Agent\TrialInputObserver;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Config\SecretFiles;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * HOW THE HOUSE EXERCISES A CAPABILITY (greenhouse decisions/0605, R1): in ONE trial that is discarded — every
 * operation it declares, in catalogue order, twice; each operation read by its worst answer: it answered, it refused,
 * or it threw. The runner here is a stand-in that answers in the shapes the real one prints; the real one, on a real
 * capability, is in {@see TheRunnerOfAnExerciseCallsAnOperationWithWhatItDeclaresTest}.
 *
 * It goes through the house's own trial runner — the one confinement there is — and nothing of it stays: no copy, no
 * trial anyone could list or promote, and the house byte for byte as it was. The tests that need a real sandbox skip
 * where the kernel refuses one; the half that fails closed never skips.
 */
final class ACapabilityIsExercisedInOneTrialThatIsDiscardedTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testEveryOperationRunsTwiceInCatalogueOrderAndIsReadByItsWorstAnswer(): void
    {
        $root = $this->root();

        $exercise = $this->exercise($root, ['stub:answers', 'stub:answers-in-its-own-shape', 'stub:refuses', 'stub:refuses-with-a-colon', 'stub:throws-the-second-time']);

        self::assertSame('threw', $exercise['exercised']);
        self::assertSame(5, $exercise['operations']);
        self::assertSame(10, $exercise['calls']);
        self::assertSame(
            [
                'stub:answers' => ['answered', 'answered'],
                'stub:answers-in-its-own-shape' => ['answered', 'answered'],
                'stub:refuses' => ['refused', 'refused'],
                'stub:refuses-with-a-colon' => ['refused', 'refused'],
                'stub:throws-the-second-time' => ['answered', 'threw'],
            ],
            $exercise['answers'],
            'the second pass finds what only fails once something exists — and a refusal that reads «Class: message» is a refusal',
        );
        self::assertSame(['answered' => 2, 'refused' => 2, 'threw' => 1], ['answered' => $exercise['answered'], 'refused' => $exercise['refused'], 'threw' => $exercise['threw']]);
        self::assertSame(
            [['operation' => 'stub:throws-the-second-time', 'class' => 'TypeError', 'kind' => 'engine', 'line' => 'array_merge(): Argument #1 must be of type array, null given', 'pass' => 2]],
            $exercise['thrown'],
        );
    }

    public function testWhatAnswersOrRefusesRan(): void
    {
        $exercise = $this->exercise($this->root(), ['stub:refuses', 'stub:answers']);

        self::assertSame('ran', $exercise['exercised']);
        self::assertSame(['stub:answers' => ['answered', 'answered'], 'stub:refuses' => ['refused', 'refused']], $exercise['answers'], 'in the order of the catalogue: by name');
        self::assertSame([], $exercise['thrown']);
        self::assertSame(TrialWorkspace::BOUNDS, $exercise['bounds']);
        self::assertArrayNotHasKey('why', $exercise);
    }

    public function testWhatIsThrownIsNamedByItsClassAndItsFirstLine(): void
    {
        $exercise = $this->exercise($this->root(), ['stub:throws', 'stub:throws-an-exception-of-the-apps']);

        self::assertSame('threw', $exercise['exercised']);
        self::assertSame(
            [
                ['operation' => 'stub:throws', 'class' => 'Error', 'kind' => 'engine', 'line' => 'Call to undefined method App\Plugins\Ledger\Accounts::add()', 'pass' => 1],
                ['operation' => 'stub:throws-an-exception-of-the-apps', 'class' => 'App\Plugins\Ledger\NoSuchAccount', 'kind' => 'app', 'line' => 'no account «7»', 'pass' => 1],
            ],
            $exercise['thrown'],
            'one line each, the first of what was thrown — and whether it is the engine\'s or the app\'s, said by the runner and not read from a sentence',
        );
    }

    public function testAProcessThatEndsWithoutAnAnswerThrewToo(): void
    {
        $exercise = $this->exercise($this->root(), ['stub:dies', 'stub:exits-quietly']);

        self::assertSame('threw', $exercise['exercised']);
        self::assertSame(
            [
                ['operation' => 'stub:dies', 'class' => 'fatal', 'kind' => 'fatal', 'line' => 'PHP Fatal error:  Cannot redeclare App\Plugins\Ledger\helper() in /app/src/Plugins/Ledger/helpers.php on line 9', 'pass' => 1],
                ['operation' => 'stub:exits-quietly', 'class' => 'fatal', 'kind' => 'fatal', 'line' => 'the process ended with exit 0 and no answer', 'pass' => 1],
            ],
            $exercise['thrown'],
        );
    }

    public function testACallTheCeilingHadToStopThrewAndIsNotRunAgain(): void
    {
        $started = microtime(true);

        $exercise = $this->exercise($this->root(), ['stub:answers', 'stub:sleeps'], ceiling: 1);

        self::assertSame('threw', $exercise['exercised']);
        self::assertSame(['stub:answers' => ['answered', 'answered'], 'stub:sleeps' => ['threw']], $exercise['answers'], 'an operation that had to be stopped is not waited for twice');
        self::assertSame(3, $exercise['calls']);
        self::assertSame([['operation' => 'stub:sleeps', 'class' => 'timeout', 'kind' => 'timeout', 'line' => 'no answer within 1 s: the house stopped it', 'pass' => 1]], $exercise['thrown']);
        self::assertLessThan(8.0, microtime(true) - $started, 'stopped at the ceiling it was given, not at the house\'s usual one');
    }

    public function testEachRequiredInputGetsOneValueOfItsDeclaredType(): void
    {
        $operation = $this->operation('ledger:typed', [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'count' => ['type' => 'integer'],
                'ratio' => ['type' => 'number'],
                'active' => ['type' => 'boolean'],
                'kind' => ['type' => 'string', 'enum' => ['savings', 'current']],
                'tags' => ['type' => 'array'],
                'meta' => ['type' => 'object'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['name', 'count', 'ratio', 'active', 'kind', 'tags', 'meta'],
        ]);

        self::assertSame(
            ['name' => '7', 'count' => 7, 'ratio' => 7, 'active' => true, 'kind' => 'savings', 'tags' => [], 'meta' => []],
            CapabilityExercise::inputFor($operation),
            'the first of its enum when it has one; nothing for what is not required',
        );
        self::assertSame([], CapabilityExercise::inputFor($this->operation('stub:answers', null)), 'an operation that declares no input is called with none');
        self::assertSame(['id' => '7'], CapabilityExercise::inputFor($this->operation('x', ['required' => ['id']])), 'a required input that declares no type is given text');
        self::assertSame(['n' => 7], CapabilityExercise::inputFor($this->operation('x', ['properties' => ['n' => ['type' => ['null', 'integer']]], 'required' => ['n']])), 'a type that may be null is given its other type');
    }

    public function testNothingOfItStaysInTheHouse(): void
    {
        $root = $this->root();
        file_put_contents($root . '/var/herramientas.json', '{"kept": true}');
        $before = $this->digest($root);

        $exercise = $this->exercise($root, ['stub:answers', 'stub:writes-into-the-house', 'stub:throws']);

        self::assertSame(['answered', 'answered'], $exercise['answers']['stub:writes-into-the-house'], 'it ran, and the write it tried did not land');
        self::assertFileDoesNotExist($root . '/written-by-the-exercise.txt');
        self::assertSame($before, $this->digest($root), 'the house is byte for byte what it was: no copy, no record, no file moved');
        self::assertDirectoryDoesNotExist($root . '/var/exercises');
        self::assertDirectoryDoesNotExist($root . '/var/trials', 'and it never was a trial');
    }

    public function testTheCopyIsNoTrialAnyoneCanListOpenOrPromote(): void
    {
        $root = $this->root();

        $copy = TrialWorkspace::forExercise($root, 'e1', $this->stub());

        self::assertSame($root . '/var/exercises/e1/copy', $copy->copy);
        self::assertSame($root . '/var/exercises/e1', $copy->baseDirectory());
        self::assertFileExists($copy->runnerPath());
        self::assertSame([], TrialWorkspace::ids($root), 'no list of trials holds it, even while it stands');
        self::assertNull(TrialWorkspace::open($root, 'e1'), 'and nothing can open it by its id');
        self::assertNull(TrialWorkspace::promotedPaths($root, 'e1'));
        TrialWorkspace::capUndecided($root, 0);
        self::assertDirectoryExists($copy->copy, 'the sweep of undecided trials does not reach it');

        $copy->discard();

        self::assertDirectoryDoesNotExist($root . '/var/exercises/e1');
    }

    /** A secret an exercised operation wrote into its copy is masked from every confined process, as a trial's is. */
    public function testASecretLeftInAnExercisesCopyIsOneTheHouseMasks(): void
    {
        $root = $this->root();
        $copy = TrialWorkspace::forExercise($root, 'e1', $this->stub());
        self::assertNotContains($copy->copy . '/.env', SecretFiles::existingUnder($root), 'the control: the copy is made without one');

        file_put_contents($copy->copy . '/.env', 'KEY=x');

        self::assertContains($copy->copy . '/.env', SecretFiles::existingUnder($root));
        self::assertContains($copy->copy . '/.env', (new TrialRunner())->maskArgs($root));
    }

    /** The copy is made by the copier a trial has: it leaves out the house's state and every file that holds a secret. */
    public function testTheCopyLeavesOutTheStateOfTheHouseAndItsSecrets(): void
    {
        $root = $this->root();
        file_put_contents($root . '/.env', 'KEY=x');
        file_put_contents($root . '/var/herramientas.json', '{"kept": true}');
        mkdir($root . '/var/exercises/older/copy', 0o777, true);
        file_put_contents($root . '/var/exercises/older/copy/left.txt', 'x');

        $copy = TrialWorkspace::forExercise($root, 'e2', $this->stub());

        self::assertFileExists($copy->copy . '/src/Plugins/Ledger/Ledger.php');
        self::assertFileDoesNotExist($copy->copy . '/.env');
        self::assertFileDoesNotExist($copy->copy . '/var/herramientas.json', 'it starts on an empty store, as every trial does');
        self::assertDirectoryDoesNotExist($copy->copy . '/var/exercises', 'and no copy holds another');
    }

    /** It is no session's authoring call: what observes the inputs of one is not asked. */
    public function testNothingObservesItsInputsAsASessionsCall(): void
    {
        $observer = new class () implements TrialInputObserver {
            public int $asked = 0;

            public function before(TrialInputAttempt $attempt): void
            {
                ++$this->asked;
            }

            public function after(TrialInputAttempt $attempt, int $exit): array
            {
                ++$this->asked;

                return [];
            }
        };
        $runner = new TrialRunner(inputObserver: $observer);
        if (! $runner->available()) {
            self::markTestSkipped('no unprivileged user namespace here: the sandbox cannot be exercised');
        }
        $root = $this->root();

        // A capability may name an operation as it likes — even with the two names the observer watches for.
        $exercise = CapabilityExercise::of($root, 'Ledger', ['test', 'implement'], $runner, $this->stub());

        self::assertSame(4, $exercise['calls']);
        self::assertSame(0, $observer->asked);
    }

    public function testATrialKeepsLivingWhereItDid(): void
    {
        $root = $this->root();

        $trial = TrialWorkspace::materialize($root, 't1', $this->stub());

        self::assertSame($root . '/var/trials/t1', $trial->baseDirectory());
        self::assertSame(['t1'], TrialWorkspace::ids($root));
        $trial->discard();
        self::assertSame([], TrialWorkspace::ids($root));
    }

    public function testWithoutAConfinementNothingRunsAndTheHouseSaysWhy(): void
    {
        $root = $this->root();
        $before = $this->digest($root);

        $exercise = CapabilityExercise::of($root, 'Ledger', ['stub:answers'], new TrialRunner(bwrap: '/nonexistent/bwrap'), $this->stub());

        self::assertSame(['exercised' => 'unjudged', 'why' => 'this house cannot confine a process', 'operations' => 1, 'calls' => 0], $exercise);
        self::assertSame($before, $this->digest($root));
        self::assertDirectoryDoesNotExist($root . '/var/exercises', 'no copy is made for a run that will not happen');
    }

    public function testAnOperationTheRunnerDoesNotFindLeavesItUnjudgedAndNotThrown(): void
    {
        $exercise = $this->exercise($this->root(), ['stub:answers', 'stub:is-not-found']);

        self::assertSame('unjudged', $exercise['exercised'], 'the house could not run it: that is not the operation throwing');
        self::assertSame('the trial runner found no operation «stub:is-not-found»', $exercise['why']);
        self::assertSame([], $exercise['thrown']);
    }

    public function testWhatThrewIsSaidEvenWhenAnotherWasNotFound(): void
    {
        $exercise = $this->exercise($this->root(), ['stub:is-not-found', 'stub:throws']);

        self::assertSame('threw', $exercise['exercised'], 'what the house did see thrown is not hidden by what it could not run');
        self::assertSame('stub:throws', $exercise['thrown'][0]['operation']);
    }

    public function testACapabilityThatDeclaresNothingIsNotExercised(): void
    {
        self::assertSame(
            ['exercised' => 'unjudged', 'why' => 'the house finds no operation of «Ledger» to run', 'operations' => 0, 'calls' => 0],
            CapabilityExercise::of($this->root(), 'Ledger', [], $this->realRunner(), $this->stub()),
        );
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private function exercise(string $root, array $names, int $ceiling = CapabilityExercise::CEILING): array
    {
        return CapabilityExercise::of($root, 'Ledger', $names, $this->realRunner(), $this->stub(), $ceiling);
    }

    /** @param array<string, mixed>|null $schema */
    private function operation(string $name, ?array $schema): Operation
    {
        return new Operation(name: $name, description: "What {$name} does.", handler: static fn (array $input): array => ['ok' => true], inputSchema: $schema);
    }

    private function realRunner(): TrialRunner
    {
        $runner = new TrialRunner();
        if (! $runner->available()) {
            self::markTestSkipped('no unprivileged user namespace here: the sandbox cannot be exercised');
        }

        return $runner;
    }

    private function stub(): string
    {
        return \dirname(__DIR__) . '/Fixtures/exercise-stub-runner.php';
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-exercise-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/Plugins/Ledger', 0o777, true);
        mkdir($root . '/var', 0o777, true);
        file_put_contents($root . '/src/Plugins/Ledger/Ledger.php', "<?php\n// the plugin\n");
        file_put_contents($root . '/composer.json', '{}');
        $this->roots[] = $root;

        return (string) realpath($root);
    }

    /** Every file of the house and what it holds — and every directory, so an empty one left behind shows too. */
    private function digest(string $root): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $entries[] = substr($entry->getPathname(), \strlen($root)) . ($entry->isDir() ? '/' : ':' . hash_file('sha256', $entry->getPathname()));
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }
}
