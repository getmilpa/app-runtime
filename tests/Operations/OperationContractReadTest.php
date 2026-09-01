<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Command\CommandProvider;
use Milpa\Command\DeclaredCondition;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\DevTools\Operations\DevToolsOperations;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * F-SURF (greenhouse decisions/0183): `operation:contract` is the ONE uniform surface for reading
 * what an operation declares about itself — and the answer is read off the declaration, never
 * derived or invented.
 *
 * The laws encoded here:
 *
 * - the shape is UNIFORM: every key present on every answer, empty lists and nulls where an
 *   operation declares nothing;
 * - a capability-loaded operation (devtools' `make`, enabled through `config/operations.php`)
 *   answers exactly like a native one, and its answer is IDENTICAL to the provider's declaration;
 * - resolution is by IDENTITY, not spelling — `operation_contract` and `operation.contract` are
 *   the same act as `operation:contract`;
 * - effects and authority in the answer EQUAL the declared EffectProfile;
 * - an unknown operation is a verdict (`ok:false`, naming it), never an exception.
 */
final class OperationContractReadTest extends TestCase
{
    /** The uniform shape: EVERY key, always — the reader never has to probe which keys exist. */
    private const UNIFORM_KEYS = [
        'ok',
        'name',
        'description',
        'inputs',
        'effects',
        'authority',
        'mutating',
        'requiresConfirmation',
        'namedTarget',
        'surfaces',
        'preconditions',
        'postconditions',
        'artifacts',
        'observableEvidence',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-contract-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents(
            $this->root . '/config/operations.php',
            "<?php\n\nreturn [\n"
                . '    \Milpa\AppRuntime\Operations\AgentOperations::class,' . "\n"
                . '    \Milpa\AppRuntime\Operations\CapabilityOperations::class,' . "\n"
                . '    \Milpa\DevTools\Operations\DevToolsOperations::class,' . "\n"
                . '    \Milpa\AppRuntime\Tests\Operations\ContractDeclaringProvider::class,' . "\n"
                . "];\n",
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/config/operations.php');
        @rmdir($this->root . '/config');
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function operations(): AgentOperations
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);

        return new AgentOperations($container);
    }

    /** A capability-loaded operation answers with ITS OWN declaration — identical, not summarised. */
    public function testACapabilityLoadedOperationAnswersWithItsDeclaredContract(): void
    {
        $answer = $this->operations()->contractFor(['name' => 'make']);

        self::assertTrue($answer['ok']);
        self::assertSame(self::UNIFORM_KEYS, array_keys($answer), 'the shape is uniform: every key, always');
        self::assertSame('make', $answer['name']);

        $declared = $this->declaredOperation(new DevToolsOperations(), 'make');
        $asArrays = static fn (array $conditions): array => array_map(
            static fn (DeclaredCondition $c): array => $c->toArray(),
            $conditions,
        );

        self::assertSame($asArrays($declared->preconditions), $answer['preconditions']);
        self::assertSame($asArrays($declared->postconditions), $answer['postconditions']);
        self::assertSame($declared->artifacts, $answer['artifacts']);
        self::assertSame($declared->observableEvidence, $answer['observableEvidence']);
        self::assertSame($declared->effectCeiling()->toArray(), $answer['effects'], 'effects EQUAL the declared profile');
        self::assertSame($declared->effectCeiling()->authority->value, $answer['authority']);
        self::assertSame($declared->namedTarget, $answer['namedTarget']);
        self::assertSame($declared->mutating, $answer['mutating']);
    }

    /** The self-contained positive control: a provider that declares everything projects everything. */
    public function testADeclaredContractProjectsVerbatim(): void
    {
        $answer = $this->operations()->contractFor(['name' => 'fixture:declared']);

        self::assertTrue($answer['ok']);
        self::assertSame(
            [['name' => 'fixture-ready', 'description' => 'the fixture must be ready before it runs']],
            $answer['preconditions'],
        );
        self::assertSame(
            [['name' => 'fixture-done', 'description' => 'the fixture must have finished when it answers']],
            $answer['postconditions'],
        );
        self::assertSame(['a fixture artifact'], $answer['artifacts']);
        self::assertSame('the fixture evidence in the result', $answer['observableEvidence']);
        self::assertSame(EffectProfile::readOnly()->toArray(), $answer['effects']);
        self::assertSame('read', $answer['authority']);
    }

    /** A native operation that declares no conditions gets the SAME shape, empty — never absent keys. */
    public function testANativeOperationWithoutDeclarationsAnswersTheSameShapeEmpty(): void
    {
        $answer = $this->operations()->contractFor(['name' => 'capabilities']);

        self::assertTrue($answer['ok']);
        self::assertSame(self::UNIFORM_KEYS, array_keys($answer));
        self::assertSame([], $answer['preconditions']);
        self::assertSame([], $answer['postconditions']);
        self::assertSame([], $answer['artifacts']);
        self::assertNull($answer['observableEvidence']);
        self::assertSame('none', $answer['effects']['mutation']);
        self::assertFalse($answer['mutating']);
    }

    /** And a native operation WITH declarations carries them — capabilities:enable declares its gate. */
    public function testANativeOperationWithDeclarationsCarriesThem(): void
    {
        $answer = $this->operations()->contractFor(['name' => 'capabilities:enable']);

        self::assertTrue($answer['ok']);
        self::assertSame(
            ['capability-named', 'capability-known'],
            array_column($answer['preconditions'], 'name'),
        );
        self::assertSame(
            ['capability-declared-after-install'],
            array_column($answer['postconditions'], 'name'),
        );
        self::assertNotSame([], $answer['artifacts']);
        self::assertNotNull($answer['observableEvidence']);
    }

    /** Identity, not spelling: the three surface spellings of one act resolve to the same contract. */
    public function testItResolvesByIdentityAcrossSurfaceSpellings(): void
    {
        $ops = $this->operations();

        foreach (['operation_contract', 'operation.contract', 'OPERATION:CONTRACT'] as $spelling) {
            $answer = $ops->contractFor(['name' => $spelling]);
            self::assertTrue($answer['ok'], "«{$spelling}» names the same act");
            self::assertSame('operation:contract', $answer['name'], 'the answer carries the DECLARED spelling');
        }

        // And the reader's own declared profile is what its answer publishes — the surface cannot
        // say something other than what the atom declares.
        $self = $ops->contractFor(['name' => 'operation:contract']);
        self::assertSame('none', $self['effects']['mutation']);
        self::assertSame('guaranteed', $self['effects']['reversibility']);
        self::assertSame('nothing-to-roll-back', $self['effects']['rollback_contract']);
        self::assertSame('read', $self['authority']);
        self::assertFalse($self['mutating']);
        self::assertSame(['cli', 'tui', 'mcp'], $self['surfaces']);
    }

    /** H-GATE-1: the unjudgeable fails closed IN WORDS — a verdict naming it, never an exception. */
    public function testAnUnknownOperationIsAVerdictNotAnException(): void
    {
        $answer = $this->operations()->contractFor(['name' => 'no:such:thing']);

        self::assertFalse($answer['ok']);
        self::assertSame('no:such:thing', $answer['name']);
        self::assertStringContainsString('unknown operation «no:such:thing»', (string) $answer['error']);
    }

    /** No name is its own refusal, and an app with no kernel says that instead of guessing. */
    public function testAMissingNameAndAMissingKernelAreNamedRefusals(): void
    {
        self::assertStringContainsString(
            'missing `name`',
            (string) $this->operations()->contractFor([])['error'],
        );

        $bare = new AgentOperations(new DIContainer());
        $answer = $bare->contractFor(['name' => 'make']);
        self::assertFalse($answer['ok']);
        self::assertStringContainsString('no kernel', (string) $answer['error']);
    }

    private function declaredOperation(CommandProvider $provider, string $name): Operation
    {
        foreach ($provider->operations() as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }
        self::fail("provider declares no operation named '{$name}'");
    }
}

/** A provider whose one operation declares its FULL contract — the projection's positive control. */
final class ContractDeclaringProvider implements CommandProvider
{
    /**
     * The single fully-declared fixture operation.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'fixture:declared',
                description: 'A fixture operation that declares its full contract',
                handler: static fn (array $input): array => ['ok' => true],
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
                effects: EffectProfile::readOnly(),
                surfaces: ['cli'],
                preconditions: [new DeclaredCondition('fixture-ready', 'the fixture must be ready before it runs')],
                postconditions: [new DeclaredCondition('fixture-done', 'the fixture must have finished when it answers')],
                artifacts: ['a fixture artifact'],
                observableEvidence: 'the fixture evidence in the result',
            ),
        ];
    }
}
