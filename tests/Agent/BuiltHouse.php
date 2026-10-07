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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Runtime\Kernel;

/**
 * A house with a capability built in it — the course's `prestamos`, as greenhouse decisions/0590 measured it.
 *
 * The capability is a real class in a real file under `<root>/src/Plugins/<Name>/`, because WHERE its class lives
 * is what makes it built. What it declares is handed to it by the test, so one test can change a contract the way a
 * promotion would.
 */
trait BuiltHouse
{
    /** The human's own key: it enrolled the seats. */
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';

    /** The resident's seat. */
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';

    /** A second resident, enrolled by the same human. */
    private const OTHER_SEAT = '68D64FAD056630690259B28EC743C1C08E3EB909';

    /** What a seat is seated with (decisions/0499): none of it is a word of the domain. */
    private const SEAT_SCOPES = ['agent:run', 'agent:read', 'plugins:read', 'plugins:write', 'plugins.config:write'];

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** A house root with a ledger where the human enrolled two seats. */
    private function root(): string
    {
        $root = sys_get_temp_dir() . '/milpa-built-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/storage/identity', 0o777, true);
        $this->dirs[] = $root;
        file_put_contents($root . '/config/identity.php', "<?php return ['rooted' => ['" . self::SEAT . "', '" . self::OTHER_SEAT . "']];");
        $ledger = $this->ledger($root);
        $ledger->record(new IdentityEnrolled(self::SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(self::OTHER_SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));

        return $root;
    }

    private function ledger(string $root): FileEnrollmentStore
    {
        return new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
    }

    /**
     * A plugin whose class lives under `<root>/src/Plugins/<name>/`, declaring what {@see declare()} hands it —
     * named the way a house names its plugins (`…\\Plugins\\<name>\\<name>`), with one entity, so the store of its
     * entities is `var/<entity>s.json` as generated code keeps it (greenhouse decisions/0588).
     *
     * @param list<Operation> $operations
     */
    private function capability(string $root, string $name, array $operations, string $entity = 'Herramienta'): object
    {
        $space = 'MilpaTest\\B' . bin2hex(random_bytes(6)) . '\\Plugins\\' . $name;
        $dir = $root . '/src/Plugins/' . $name;
        mkdir($dir . '/Entities', 0o777, true);
        file_put_contents($dir . '/Entities/' . $entity . '.php', "<?php\n// The entity this capability registers.\n");
        $file = $dir . '/' . $name . '.php';
        file_put_contents($file, <<<PHP
            <?php
            namespace {$space};
            final class {$name} implements \\Milpa\\Command\\CommandProvider, \\Milpa\\AppRuntime\\Agent\\DeclaresWorkState
            {
                /** @var list<\\Milpa\\Command\\Operation> */
                public array \$declared = [];
                /** @var array<string, mixed> */
                public array \$states = [];
                public function operations(): array
                {
                    return \$this->declared;
                }
                public function workState(): array
                {
                    return \$this->states;
                }
            }
            PHP);
        require_once $file;
        $fqcn = $space . '\\' . $name;
        $plugin = new $fqcn();
        $this->declare($plugin, $operations);

        return $plugin;
    }

    /**
     * Where the plugin says each operation's work keeps its state, when that is not the store of its entities.
     *
     * @param array<string, mixed> $states the operation's name => its paths
     */
    private function keepsStateIn(object $plugin, array $states): void
    {
        $plugin->states = $states; // @phpstan-ignore property.notFound
    }

    /**
     * What a promotion over the plugin would leave it declaring.
     *
     * @param list<Operation> $operations
     */
    private function declare(object $plugin, array $operations): void
    {
        $plugin->declared = $operations; // @phpstan-ignore property.notFound
    }

    /**
     * A kernel of that root with those plugins booted.
     *
     * @param list<object>    $plugins
     * @param list<Operation> $more    operations the house has from elsewhere (a package, `config/operations.php`)
     */
    private function kernel(string $root, array $plugins, array $more = [], ?\Milpa\Interfaces\Di\DIContainerInterface $container = null): Kernel
    {
        $commands = $more;
        foreach ($plugins as $plugin) {
            if ($plugin instanceof \Milpa\Command\CommandProvider) {
                $commands = [...$commands, ...$plugin->operations()];
            }
        }
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => $commands, 'plugins' => $plugins] + ($container === null ? [] : ['container' => $container]) as $property => $value) {
            $p = new \ReflectionProperty(Kernel::class, $property);
            $p->setAccessible(true);
            $p->setValue($kernel, $value);
        }

        return $kernel;
    }

    /**
     * The four verbs the course teaches, with the scopes it teaches.
     *
     * @return list<Operation>
     */
    private function prestamos(string $read = 'herramientas:read', string $write = 'herramientas:write'): array
    {
        return [
            $this->verb('herramientas.listar', [$read]),
            $this->verb('herramientas.agregar', [$write], mutating: true),
            $this->verb('herramientas.prestar', [$write], mutating: true),
            $this->verb('herramientas.devolver', [$write], mutating: true),
        ];
    }

    /** @param list<string> $scopes */
    private function verb(string $name, array $scopes, bool $mutating = false, ?EffectProfile $effects = null, bool $classified = true, ?\Closure $handler = null): Operation
    {
        $effects ??= !$classified ? null : ($mutating
            ? new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data)
            : EffectProfile::readOnly());

        return new Operation(
            name: $name,
            description: 'The workshop: ' . $name,
            handler: $handler ?? static fn (array $input): array => ['ok' => true, 'ran' => $name],
            inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
            mutating: $mutating,
            scopes: $scopes,
            surfaces: ['cli', 'tui', 'mcp'],
            effects: $effects,
        );
    }
}
