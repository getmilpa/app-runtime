<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Standing\StandingOperations;
use Milpa\AppRuntime\Tests\Agent\BuiltHouse;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\OperationBoundary;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use PHPUnit\Framework\TestCase;

/**
 * A standing grant is not given over a verb of a capability built in this house (greenhouse decisions/0615, decided
 * by Rod 2026-10-12).
 *
 * What opens such a verb to a seat is a person admitting that verb (decisions/0590), and that is asked before any
 * grant is. Measured on a real leg while the evidence arm was built: a grant over such a verb was signed, written and
 * listed, lent nothing, and the seat was told to ask for the admission. `standing:grant` said nothing of it.
 *
 * @subject-in milpa/app-runtime
 */
final class AGrantIsNotGivenOverAVerbBuiltInTheHouseTest extends TestCase
{
    use BuiltHouse;

    private ?LabHouse $lab = null;

    /**
     * `standing:grant`, in a house that built «Prestamos» and also holds an operation it did not build.
     *
     * @return array{Operation, string, LabHouse}
     */
    private function door(): array
    {
        // The lab house lends this one its keyring and its way of signing; the house under test is the built one.
        $this->lab = $lab = new LabHouse();
        $root = $this->root();
        // …and what it installed: the lab's operations ship in a package of this house too.
        mkdir($root . '/vendor/composer', 0o755, true);
        copy($lab->root . '/vendor/composer/installed.json', $root . '/vendor/composer/installed.json');
        $ledger = $this->ledger($root);
        $ledger->record(new IdentityEnrolled(LabHouse::PERSON, LabHouse::PERSON_SCOPES, 'key:' . LabHouse::PERSON));
        $ledger->record(new IdentityEnrolled(LabHouse::SEAT, LabHouse::SEAT_SCOPES, 'key:' . LabHouse::PERSON));
        $effect = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data);
        $path = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];
        $plugin = $this->capability($root, 'Prestamos', [new Operation('herramientas.prestar', 'Lend a tool', static fn (array $input): array => ['ok' => true], $path, mutating: true, scopes: ['herramientas:write'], effects: $effect, actsOn: static fn (array $input): ?string => 'sha256:x')]);
        $kernel = $this->kernel($root, [$plugin]);
        $container = new DIContainer();
        $policy = new PluginAuthoringPolicy($root, capabilities: static fn (): BuiltCapabilities => BuiltCapabilities::of($kernel), standing: new HouseStandingJudge($root, $lab));
        $container->registerService(OperationBoundary::class, $policy);
        $operations = PluginAuthoringPolicy::catalogue($container, [
            ...$plugin->operations(),
            $lab->operation('lab:merge'),
            ...(new StandingOperations($container, $root, $lab))->operations(),
        ]);
        $container->registerService(GrantedAuthorization::class, new \stdClass());
        $grant = array_values(array_filter($operations, static fn (Operation $operation): bool => $operation->name === 'standing:grant'))[0];
        $signed = static function (array $change) use ($lab, $container, $grant): array {
            $document = $lab->document($change);
            $container->replaceService(GrantedAuthorization::class, $lab->signature(LabHouse::PERSON, 'standing:grant', ['grant' => $document]));

            return ($grant->handler)(['grant' => $document], null, new ToolContext(principal: 'key:' . LabHouse::PERSON, channel: 'cli', scopes: LabHouse::PERSON_SCOPES));
        };

        return [new Operation('give', 'the door, with a signature', static fn (array $change): array => $signed($change)), $root, $lab];
    }

    protected function tearDown(): void
    {
        $this->lab?->destroy();
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->dirs = [];
    }

    public function testAGrantOverAVerbBuiltInTheHouseIsRefusedAndTheSentenceSaysWhatOpensIt(): void
    {
        [$door, $root] = $this->door();

        $given = ($door->handler)(['covers' => [['operation' => 'herramientas.prestar']]]);

        self::assertFalse($given['ok']);
        self::assertStringContainsString('`herramientas:prestar` is a verb of «Prestamos», a capability built in this house', (string) $given['error']);
        self::assertStringContainsString('a person admitting that verb, not a standing grant', (string) $given['error']);
        self::assertStringContainsString('nothing was granted', (string) $given['error']);
        self::assertFileDoesNotExist($root . '/' . StandingLedger::PATH);
    }

    public function testOneBuiltVerbAmongWhatAGrantCoversRefusesTheWholeGrant(): void
    {
        [$door, $root] = $this->door();

        $given = ($door->handler)(['covers' => [['operation' => 'lab:merge'], ['operation' => 'herramientas.prestar']]]);

        self::assertStringContainsString('a capability built in this house', (string) ($given['error'] ?? 'GIVEN'));
        self::assertFileDoesNotExist($root . '/' . StandingLedger::PATH);
    }

    public function testAnOperationTheHouseDidNotBuildIsNotRefusedForThat(): void
    {
        // Control: the same door, the same grant, over an operation no capability of this house declares. It is
        // refused further on — this house has no `lab:scan` to measure by — and never for being built here.
        [$door] = $this->door();

        $given = ($door->handler)(['covers' => [['operation' => 'lab:merge']]]);

        self::assertStringNotContainsString('built in this house', (string) ($given['error'] ?? ''));
        self::assertStringContainsString('measured by `lab:scan`', (string) ($given['error'] ?? 'GIVEN'));
    }
}
