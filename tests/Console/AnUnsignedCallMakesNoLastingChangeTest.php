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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\SequenceReceipts;
use Milpa\Container\DIContainer;
use Milpa\DevTools\Operations\DevToolsOperations;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use PHPUnit\Framework\TestCase;

/**
 * An unsigned call from the terminal makes no lasting change (greenhouse decisions/0522).
 *
 * Measured (evidence/1050): once the seat's receipt was released, the resident's unsigned legs ran as `local-shell`
 * with `*` — 21 build operations outside the seat — and an unsigned probe, outside any session, staged a write to
 * `HelloPlugin`, a scope the seat never held. The door calls here run `bin/coa`'s own `Application` in a CHILD
 * process, as a terminal would.
 *
 * @guards an unsigned call that declares a lasting change is refused before anything runs, and says how to sign;
 *         what changes nothing that lasts — a read, the dev server — runs as it always did
 *
 * @refuses an unsigned agent leg with no standing receipt (it never runs as the terminal), and an unsigned staging write
 *
 * @subject-in milpa/app-runtime
 */
final class AnUnsignedCallMakesNoLastingChangeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('HelloPlugin');
        file_put_contents($this->root . '/config/operations.php', '<?php return [' . implode(', ', array_map(
            static fn (string $class): string => "\\{$class}::class",
            [TrialOperations::class, AgentOperations::class, DevToolsOperations::class],
        )) . "];\n");
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testAnUnsignedAgentLegWithNoReceiptIsRefusedAndNeverRunsAsTheTerminal(): void
    {
        [$exit, $out] = $this->coa(['agent', '--session=camino-blog', '--prompt=continue']);

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('This call is not signed, and an unsigned call changes nothing that lasts: «agent» declares a persistent change (write_as_user).', $out);
        self::assertStringContainsString('No signed receipt stands for «camino-blog»', $out);
        self::assertStringContainsString('Sign it with --sign', $out);
        self::assertStringNotContainsString('local-shell', $out);
        self::assertFileDoesNotExist($this->root . '/var/agent-sessions.jsonl', 'nothing ran, so no session was opened');
    }

    public function testAnUnsignedStagingWriteToAPluginIsRefusedAndStagesNothing(): void
    {
        $live = (string) file_get_contents($this->root . '/src/Plugins/HelloPlugin/HelloPlugin.php');

        [$exit, $out] = $this->coa(['implement', '--plugin=HelloPlugin', '--class=HelloPlugin', '--mode=reset', '--content=']);

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('«implement» declares a persistent change', $out);
        self::assertStringContainsString('It does not run as the terminal either. Nothing ran.', $out);
        self::assertFileDoesNotExist($this->root . '/src/Plugins/HelloPlugin/HelloPlugin.php.milpa-part');
        self::assertSame($live, file_get_contents($this->root . '/src/Plugins/HelloPlugin/HelloPlugin.php'));
    }

    public function testAnUnsignedReadStillRuns(): void
    {
        [$exit, $out] = $this->coa(['sandbox:list']);

        self::assertSame(0, $exit, $out);
        self::assertStringNotContainsString('not signed', $out);
    }

    public function testWhatLastsIsReadFromTheOperationsOwnDeclaration(): void
    {
        $profile = static fn (Mutation $m): EffectProfile => new EffectProfile($m, Externality::None, $m === Mutation::None ? Reversibility::NotApplicable : Reversibility::ManualRecovery, Authority::Read, subject: Subject::None);
        $op = static fn (?EffectProfile $e): Operation => new Operation(name: 'x', description: 'd', handler: static fn (): array => [], effects: $e);

        self::assertFalse(UnsignedTerminal::lasts($op($profile(Mutation::None))), 'a read');
        self::assertFalse(UnsignedTerminal::lasts($op($profile(Mutation::Ephemeral))), 'the dev server');
        self::assertTrue(UnsignedTerminal::lasts($op($profile(Mutation::Persistent))));
        self::assertTrue(UnsignedTerminal::lasts($op($profile(Mutation::Unknown))));
        self::assertTrue(UnsignedTerminal::lasts($op(null)), 'undeclared counts as the maximum');
    }

    public function testTheRefusalSaysWhatTheOperationDeclaresAndHowToSign(): void
    {
        $implement = new Operation(
            name: 'implement',
            description: 'd',
            handler: static fn (): array => [],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data),
        );

        self::assertSame([
            'This call is not signed, and an unsigned call changes nothing that lasts: «implement» declares a persistent change (write_as_user).',
            '  It does not run as the terminal either. Nothing ran.',
            '  Sign it with --sign, or continue a sequence whose receipt still stands.',
        ], UnsignedTerminal::refusal($implement, ['plugin' => 'HelloPlugin'], null));
    }

    /** `serve` declares an ephemeral change: it starts unsigned, as the three CLI steps of the 1→8 path expect. */
    public function testTheDevServerIsNotALastingChange(): void
    {
        $serve = new Operation(
            name: 'serve',
            description: 'd',
            handler: static fn (): array => [],
            mutating: true,
            effects: new EffectProfile(Mutation::Ephemeral, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Executable),
        );

        self::assertNull(UnsignedTerminal::refusal($serve, ['port' => 18736], null));
    }

    public function testAStandingReceiptIsWhatLetsAnUnsignedCallContinue(): void
    {
        $op = (new AgentOperations(new DIContainer()))->operations();
        $agent = array_values(array_filter($op, static fn (Operation $o): bool => $o->name === 'agent'))[0];
        $receipts = new class () implements SequenceReceipts {
            public ?array $kept = null;

            public function record(string $sequence, string $operation, GrantedAuthorization $granted, mixed $result): void
            {
            }

            public function standing(string $sequence): ?array
            {
                return $sequence === 's1' ? $this->kept : null;
            }

            public function cited(string $sequence, string $operation, string $receiptId): void
            {
            }

            public function settled(string $sequence, string $operation, mixed $result): void
            {
            }
        };

        self::assertFalse(UnsignedTerminal::continuesASignedSequence($agent, ['session' => 's1', 'prompt' => 'go'], $receipts));
        $receipts->kept = ['operation' => 'agent', 'payload' => 'p', 'signature' => 's', 'fingerprint' => 'F'];
        self::assertTrue(UnsignedTerminal::continuesASignedSequence($agent, ['session' => 's1', 'prompt' => 'go'], $receipts));
        self::assertFalse(UnsignedTerminal::continuesASignedSequence($agent, ['session' => 's2', 'prompt' => 'go'], $receipts));
        self::assertFalse(UnsignedTerminal::continuesASignedSequence($agent, ['prompt' => 'a one-off'], $receipts));
        self::assertFalse(UnsignedTerminal::continuesASignedSequence($agent, ['session' => 's1', 'prompt' => 'go'], null));
    }

    public function testAnOperationThatDemandsConsentIsLeftToTheRunnersOwnAskForASignature(): void
    {
        $privileged = new Operation(
            name: 'plugins.disable-unsafe',
            description: 'd',
            handler: static fn (): array => [],
            mutating: true,
            scopes: ['plugins:write'],
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Irreversible, Authority::Privileged, subject: Subject::Executable),
        );
        $reading = new Operation(
            name: 'sandbox:list',
            description: 'd',
            handler: static fn (): array => [],
            effects: new EffectProfile(Mutation::None, Externality::None, Reversibility::NotApplicable, Authority::Read, subject: Subject::None),
        );

        self::assertTrue(UnsignedTerminal::runnerDecides($privileged, [], null));
        self::assertFalse(UnsignedTerminal::runnerDecides($reading, [], null));
    }

    /**
     * Run `coa` in a child process, with no token presented.
     *
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function coa(array $argv): array
    {
        $script = $this->root . '/var/coa.php';
        file_put_contents($script, '<?php
require ' . var_export($this->root . '/vendor/autoload.php', true) . ';
$key = new Milpa\AppRuntime\Tests\Fixtures\LabSigner();
$app = new Milpa\AppRuntime\Console\Application(' . var_export($this->root, true) . ', $key, $key);
exit($app->run(["coa", ...json_decode($argv[1], true)]));
');
        exec('MILPA_TOKEN= ' . escapeshellarg(\PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' ' . escapeshellarg((string) json_encode($argv)) . ' 2>&1', $out, $exit);

        return [$exit, implode("\n", $out)];
    }
}
