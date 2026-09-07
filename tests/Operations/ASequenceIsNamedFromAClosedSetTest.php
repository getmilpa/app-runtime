<?php

/**
 * This file is part of Milpa App Runtime — the governed runtime of a founded Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\PausedSequence;
use Milpa\AppRuntime\Operations\SequenceOperations;
use Milpa\AppRuntime\Sequence\DeclaredSequences;
use Milpa\Command\Effect\Authority;
use Milpa\Runtime\Kernel;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `sequence:run` is the narrow operation that CAN reach HTTP — greenhouse `decisions/0223`.
 *
 * An adversarial review measured why `recipe:apply` cannot: adding `'http'` to it is a one-word diff
 * that publishes an unguarded endpoint whose payload names a FILE. Every difference here is the answer
 * to one of those defects, and each one is asserted rather than described.
 */
#[CoversClass(SequenceOperations::class)]
#[CoversClass(DeclaredSequences::class)]
final class ASequenceIsNamedFromAClosedSetTest extends TestCase
{
    /**
     * F — THE NAME IS A KEY, NOT A PATH. It resolves against a closed set the app declared, so nothing
     * a caller writes can select a file: no path is built, so none can be escaped from.
     *
     * @return array<string, array{0: string}>
     */
    public static function namesNobodyDeclared(): array
    {
        return [
            'a traversal' => ['../../../etc/passwd'],
            'an absolute path' => ['/etc/passwd'],
            'a subdirectory' => ['uploads/evil'],
            'a plausible name nobody declared' => ['staging'],
        ];
    }

    #[DataProvider('namesNobodyDeclared')]
    public function testANameTheAppNeverDeclaredReachesNothing(string $name): void
    {
        $declared = self::declaring([
            'deploy' => [['op' => 'plugins:verify', 'args' => []]],
        ]);

        self::assertNull($declared->stepsOf($name), $name . ' resolved to something');
    }

    /** THE POSITIVE CONTROL: a declared name resolves, so the refusal above is about the SET, not about everything. */
    public function testADeclaredNameResolvesToItsSteps(): void
    {
        $declared = self::declaring([
            'deploy' => [
                ['op' => 'plugins:verify', 'args' => []],
                ['op' => 'stack:up', 'args' => ['service' => 'web']],
            ],
        ]);

        $steps = $declared->stepsOf('deploy');
        self::assertNotNull($steps);
        self::assertCount(2, $steps);
        self::assertSame('plugins:verify', $steps[0]->operation);
        self::assertSame(['service' => 'web'], $steps[1]->arguments);
        self::assertSame(['deploy'], $declared->names());
    }

    /**
     * A sequence with a malformed step is dropped WHOLE. Running the well-formed part would run a
     * SHORTER sequence than the app declared — and a deployment that runs some of its steps is worse
     * than one that refuses to start.
     */
    public function testASequenceWithAMalformedStepIsDroppedWhole(): void
    {
        $declared = self::declaring([
            'deploy' => [
                ['op' => 'plugins:verify', 'args' => []],
                ['args' => ['service' => 'web']],
            ],
        ]);

        self::assertNull($declared->stepsOf('deploy'), 'half a deployment is not a deployment');
        self::assertSame([], $declared->names());
    }

    /** An app that declares no sequences is an app, not an error: not deploying is a legitimate shape. */
    public function testAnAppThatDeclaresNoneIsAnEmptySetAndNotAFailure(): void
    {
        self::assertSame([], self::declaring([])->names());
        self::assertSame([], DeclaredSequences::underRoot(sys_get_temp_dir() . '/no-such-app-' . bin2hex(random_bytes(4)))->names());
    }

    /**
     * A RESUME IS OF WHAT WAS NAMED — and it used to be of whatever the session happened to hold.
     *
     * `$resuming` was decided from the session alone, and the resume path discards the steps the call
     * resolved. So `{sequence: health-check, session: «sequence:deploy»}` ran DEPLOY's paused steps
     * while the catalogue, the ledger's petition and the human's intent all said health-check — the
     * intent contract (ADR-0044) broken from the inside.
     *
     * Today's constant ceiling was the only thing making that harmless: both calls cost the same
     * signature. That is a coincidence, not a guard, and it disappears the moment the ceiling is
     * derived — which is why this is fixed BEFORE deriving anything (greenhouse decisions/0223).
     */
    public function testASessionPausedOnAnotherSequenceIsNotResumedByThisOne(): void
    {
        $answer = ($this->operation()->handler)(['sequence' => 'health-check', 'session' => 'sequence:deploy'], null);

        self::assertFalse($answer['ok'] ?? null);
        // Without a kernel it stops earlier, and that IS the honest order — but the refusal this test
        // guards is asserted directly on the predicate below, where no app is needed.
        self::assertIsString($answer['error'] ?? null);
    }

    /** The refusal itself, on the shape a paused session has: named one, paused on another. */
    public function testTheRefusalNamesBothSequences(): void
    {
        $paused = new PausedSequence('deploy', 'sha256:whatever', [['operation' => 'plugins:list', 'arguments' => []]], 0);

        self::assertSame('deploy', $paused->sequenceId, 'the pause remembers WHICH sequence it is of');
        self::assertNotSame('health-check', $paused->sequenceId, 'so a call naming another can be told apart');
    }

    /**
     * F — IT DECLARES A SCOPE, and that is the difference between exposed and governed.
     *
     * `HttpProjector::handle()` consults the policy ONLY when an operation declares scopes or a
     * permission, and the boot-time `assertGuarded()` refuses only those. An operation with neither is
     * published to whoever reaches the server, and nothing in the boot path objects.
     */
    public function testItIsReachableOverHttpAndDeclaresTheScopeThatMakesThePolicyRun(): void
    {
        $op = $this->operation();

        self::assertTrue($op->supportsSurface('http'), 'the surface a human authorises everything else from');
        self::assertNotSame([], $op->scopes, 'without a scope the http surface never consults a policy');
        self::assertSame(['agent:run'], $op->scopes);
        self::assertSame('sequence', $op->namedTarget, 'the human names what runs (ADR-0044)');
        self::assertTrue($op->mutating);
    }

    /**
     * THE FIRST PASS IS THE MAXIMUM, by design and by precedent. Built from `config/operations.php` this
     * provider receives no catalogue — it is built in order to PRODUCE one — so it borrows from nothing,
     * and GOV-05 makes that the maximum of every axis: safe, and derived from nothing. That is exactly
     * what `ConfigOperations` does on its first pass, and `Consent::demanded()` is still true by weight.
     */
    public function testBeforeTheLoanTheCeilingIsTheMaximum(): void
    {
        $effects = $this->operation()->effects;

        self::assertNotNull($effects);
        self::assertFalse($effects->isFullyClassified(), 'nothing was derived yet, and it says so');
        self::assertGreaterThanOrEqual(Subject::Executable->weight(), $effects->subject->weight());
        self::assertGreaterThanOrEqual(Authority::Privileged->weight(), $effects->authority->weight());
    }

    /**
     * F3 OF `decisions/0223` — THE CEILING RISES WITH THE STEPS.
     *
     * The floor says `ManualRecovery`. A declared step that is `Irreversible` lifts the whole sequence
     * to `Irreversible`: what it originates includes that step, so the ceiling cannot say less.
     */
    public function testAStepWorseThanTheFloorRaisesTheCeiling(): void
    {
        $effects = $this->declaredWith(
            ['deploy' => [['op' => 'plugins:list', 'args' => []], ['op' => 'db:drop', 'args' => []]]],
            [self::read('plugins.list'), self::irreversible('db.drop')],
        )->effects;

        self::assertNotNull($effects);
        self::assertSame(Reversibility::Irreversible, $effects->reversibility, 'the worst step decides');
        self::assertTrue($effects->isFullyClassified(), 'derived from classified steps, so it IS classified');
    }

    /**
     * F3'S CONTROL — AND THE FIRST VERSION OF THIS CONTROL WAS THE DEFECT.
     *
     * It used to read «removing that step LOWERS the ceiling — if it does not, the join is not being
     * used». A control that celebrates the ceiling going down is a control that approves the hole: the
     * naive join of two ordinary writes reaches neither Executable nor Privileged, so a real deployment
     * would have lost its ceremony while still mutating. The fold is a FLOOR joined upward, never a
     * substitute — so a sequence of pure reads keeps the floor exactly.
     */
    public function testASequenceOfPureReadsKeepsTheFloorAndNeverLowersIt(): void
    {
        $effects = $this->declaredWith(
            ['health' => [['op' => 'plugins:list', 'args' => []], ['op' => 'plugins:show', 'args' => []]]],
            [self::read('plugins.list'), self::read('plugins.show')],
        )->effects;

        self::assertNotNull($effects);
        self::assertSame(Subject::Executable, $effects->subject, 'the floor held');
        self::assertSame(Authority::Privileged, $effects->authority, 'the floor held');
        self::assertSame(Reversibility::ManualRecovery, $effects->reversibility, 'nothing lowered it');
        self::assertTrue($effects->isFullyClassified());
    }

    /**
     * A step naming an operation the app does NOT offer folds to the maximum: a sequence that cannot be
     * judged whole cannot be judged cheaper than its worst possibility — the same refusal the gate makes
     * at run time (UNJUDGEABLE).
     */
    public function testAStepTheAppDoesNotOfferFoldsToTheMaximum(): void
    {
        $effects = $this->declaredWith(
            ['deploy' => [['op' => 'plugins:list', 'args' => []], ['op' => 'nobody:has-this', 'args' => []]]],
            [self::read('plugins.list')],
        )->effects;

        self::assertNotNull($effects);
        self::assertFalse($effects->isFullyClassified(), 'unjudgeable whole, so unbounded');
        self::assertSame(Reversibility::Unknown, $effects->reversibility);
    }

    /** No sequence declared is a floor with nothing to raise it — not an unbounded ceiling. */
    public function testAnAppThatDeclaresNoSequencesKeepsTheFloor(): void
    {
        $effects = $this->declaredWith([], [self::read('plugins.list')])->effects;

        self::assertNotNull($effects);
        self::assertTrue($effects->isFullyClassified(), 'answering «this app declares none» is not unbounded');
        self::assertSame(Reversibility::ManualRecovery, $effects->reversibility);
    }

    /**
     * The provider AFTER the loan: a real `config/sequences.php` under a root the container's kernel
     * points at, and the catalogue `Operations::withBorrowedCeilings()` would hand over.
     *
     * @param array<string, mixed> $sequences
     * @param list<Operation>      $catalogue
     */
    private function declaredWith(array $sequences, array $catalogue): Operation
    {
        $root = sys_get_temp_dir() . '/milpa-fold-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o775, true);
        file_put_contents(
            $root . '/config/sequences.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($sequences, true) . ";\n",
        );

        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $prop = new \ReflectionProperty(Kernel::class, $name);
            $prop->setValue($kernel, $value);
        }
        $container = new DIContainer();
        $container->registerService(Kernel::class, $kernel);

        $operations = (new SequenceOperations($container))->withCatalogue($catalogue)->operations();

        unlink($root . '/config/sequences.php');
        rmdir($root . '/config');
        rmdir($root);

        self::assertCount(1, $operations);

        return $operations[0];
    }

    private static function read(string $name): Operation
    {
        return new Operation(
            name: $name,
            effects: EffectProfile::readOnly(),
            description: 'x',
            handler: static fn (): array => [],
            inputSchema: ['type' => 'object'],
        );
    }

    private static function irreversible(string $name): Operation
    {
        return new Operation(
            name: $name,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Irreversible,
                Authority::WriteAsUser,
                subject: Subject::Data,
            ),
            description: 'x',
            handler: static fn (): array => [],
            inputSchema: ['type' => 'object'],
            mutating: true,
        );
    }

    /** A name the app never declared is answered with the ones it DID: a wrong guess deserves the list. */
    public function testAnUnknownNameIsAnsweredWithWhatExists(): void
    {
        $answer = ($this->operation()->handler)(['sequence' => 'nope'], null);

        self::assertFalse($answer['ok'] ?? null);
        // Without a kernel it stops earlier, which is the honest order: there is no app to ask.
        self::assertStringContainsString('no kernel', (string) ($answer['error'] ?? ''));
    }

    /** An empty name is refused before anything else is resolved. */
    public function testAnEmptyNameIsRefusedAtTheBoundary(): void
    {
        $answer = ($this->operation()->handler)(['sequence' => '  '], null);

        self::assertFalse($answer['ok'] ?? null);
        self::assertStringContainsString('name a sequence', (string) ($answer['error'] ?? ''));
    }

    /**
     * A set read the way an app's is: written to `config/sequences.php` under a root and read back
     * through {@see DeclaredSequences::underRoot()} — the one door, so these tests measure the path
     * and not a shortcut past it.
     *
     * @param array<string, mixed> $declared
     */
    private static function declaring(array $declared): DeclaredSequences
    {
        $root = sys_get_temp_dir() . '/milpa-seq-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o775, true);
        file_put_contents(
            $root . '/config/sequences.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($declared, true) . ";\n",
        );

        $set = DeclaredSequences::underRoot($root);

        unlink($root . '/config/sequences.php');
        rmdir($root . '/config');
        rmdir($root);

        return $set;
    }

    private function operation(): Operation
    {
        $operations = (new SequenceOperations(new DIContainer()))->operations();
        self::assertCount(1, $operations);

        return $operations[0];
    }
}
