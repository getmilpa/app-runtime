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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Agent\BuiltCapabilities;
use Milpa\AppRuntime\Agent\CapabilityAdmissions;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Tests\Fixtures\LabSigner;
use PHPUnit\Framework\TestCase;

/**
 * The terminal's door: a seat's own key signs for a verb of a capability built in the house (greenhouse
 * decisions/0590). This is the call the baseline measured on the published train, by execution: the booted
 * kernel, the catalogue, the runner and the policy are the shipped ones; only the key is a lab's.
 *
 * Measured there: the course's capability refused the seat; the same capability naming its scopes after ones the
 * seat already held let it read and write; a read declaring no scope ran. Here none of the three runs until a
 * person admits it, and then they run — without the seat holding a new word.
 */
final class ASeatSignsForABuiltVerbOnTheTerminalTest extends TestCase
{
    private const HUMAN = 'BBBB2222CCCC3333DDDD4444EEEE5555FFFF6666';
    private const SEAT = 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777';
    private const SEAT_SCOPES = ['agent:run', 'agent:read', 'graph:run', 'graph:read'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-terminal-built-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o777, true);
        mkdir($this->root . '/storage/identity', 0o777, true);
        $this->ledger()->record(new IdentityEnrolled(self::SEAT, self::SEAT_SCOPES, 'key:' . self::HUMAN));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheCoursesCapabilityIsRefusedWithTheHousesSentence(): void
    {
        $this->build("'herramientas:read'", "'herramientas:write'");

        [$exit, $output] = $this->asTheSeat(['herramientas:listar']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('«herramientas.listar» is a verb of the capability «Prestamos»', $output);
        self::assertStringContainsString('no person has admitted it for this seat', $output);
        self::assertStringNotContainsString('Missing required scope', $output);
    }

    /** The baseline's second row. */
    public function testNamingItsScopesAfterOnesTheSeatHoldsNoLongerOpensIt(): void
    {
        $this->build("'agent:read'", "'agent:run'");

        [$read, $said] = $this->asTheSeat(['herramientas:listar']);
        [$write] = $this->asTheSeat(['herramientas:agregar', '--nombre=Taladro']);

        self::assertSame([1, 1], [$read, $write]);
        self::assertStringContainsString('no person has admitted it for this seat', $said);
        self::assertFileDoesNotExist($this->root . '/var/taller.json', 'nothing was written');
    }

    /** The baseline's third row. */
    public function testAReadThatDeclaresNoScopeNoLongerRuns(): void
    {
        $this->build('', "'herramientas:write'");

        [$exit, $output] = $this->asTheSeat(['herramientas:listar']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('no person has admitted it for this seat', $output);
    }

    public function testAfterAPersonAdmitsItTheSeatWritesAndHoldsNoNewWord(): void
    {
        $this->build("'herramientas:read'", "'herramientas:write'");
        $this->admit('herramientas:write');

        [$exit, $output] = $this->asTheSeat(['herramientas:agregar', '--nombre=Taladro', '--json']);

        self::assertSame(0, $exit, $output);
        self::assertSame(['Taladro'], json_decode((string) file_get_contents($this->root . '/var/taller.json'), true));
        self::assertSame(self::SEAT_SCOPES, $this->ledger()->scopesFor(self::SEAT));
        // Reading was not admitted with it.
        self::assertSame(1, $this->asTheSeat(['herramientas:listar'])[0]);
    }

    /** Whoever holds the terminal and signs with no enrolled key is the operator, as before. */
    public function testTheOperatorOfTheTerminalIsNotAsked(): void
    {
        $this->build("'herramientas:read'", "'herramientas:write'");

        [$exit, $output] = $this->dispatch(new Application($this->root), ['herramientas:listar', '--json']);

        self::assertSame(0, $exit, $output);
    }

    /**
     * Put the capability in the house's own tree, as a promotion would, and say it boots.
     *
     * @param string $read  the scope its read declares, as PHP source; empty for none
     * @param string $write the scope its write declares, as PHP source
     */
    private function build(string $read, string $write): void
    {
        $class = 'T' . bin2hex(random_bytes(6));
        $dir = $this->root . '/src/Plugins/Prestamos';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/Prestamos.php', <<<PHP
            <?php
            namespace MilpaTest\\Built;

            use Milpa\\Command\\Effect\\Authority;
            use Milpa\\Command\\Effect\\EffectProfile;
            use Milpa\\Command\\Effect\\Externality;
            use Milpa\\Command\\Effect\\Mutation;
            use Milpa\\Command\\Effect\\Reversibility;
            use Milpa\\Command\\Effect\\Subject;
            use Milpa\\Command\\Operation;

            #[\\Milpa\\Attributes\\PluginMetadata(version: '0.1.0', author: 'lab', site: 'https://example.test', name: 'Prestamos', type: 'Service')]
            final class {$class} implements \\Milpa\\Interfaces\\Plugin\\PluginInterface, \\Milpa\\Command\\CommandProvider
            {
                public function __construct(private readonly \\Milpa\\Interfaces\\Di\\DIContainerInterface \$container)
                {
                }
                public function boot(): void
                {
                }
                public function install(): void
                {
                }
                public function uninstall(): void
                {
                }
                public function enable(): void
                {
                }
                public function disable(): void
                {
                }
                public function operations(): array
                {
                    \$store = \\dirname(__DIR__, 3) . '/var/taller.json';

                    return [
                        new Operation(
                            name: 'herramientas.listar',
                            description: 'Consultar herramientas.',
                            handler: static fn (array \$input): array => ['ok' => true, 'herramientas' => is_file(\$store) ? json_decode((string) file_get_contents(\$store), true) : []],
                            scopes: [{$read}],
                            surfaces: ['cli', 'tui', 'mcp'],
                            effects: EffectProfile::readOnly(),
                        ),
                        new Operation(
                            name: 'herramientas.agregar',
                            description: 'Registrar una herramienta.',
                            handler: static function (array \$input) use (\$store): array {
                                @mkdir(\\dirname(\$store), 0o777, true);
                                \$all = is_file(\$store) ? json_decode((string) file_get_contents(\$store), true) : [];
                                \$all[] = \$input['nombre'];
                                file_put_contents(\$store, json_encode(\$all));

                                return ['ok' => true, 'herramientas' => \$all];
                            },
                            inputSchema: ['type' => 'object', 'required' => ['nombre'], 'properties' => ['nombre' => ['type' => 'string']]],
                            mutating: true,
                            scopes: [{$write}],
                            surfaces: ['cli', 'tui', 'mcp'],
                            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data),
                        ),
                    ];
                }
            }
            PHP);
        require_once $dir . '/Prestamos.php';
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => \\' . self::class . '::container(' . var_export($this->root, true) . '), "plugins" => [\\MilpaTest\\Built\\' . $class . '::class]];');
    }

    /** The container a house's `config/boot.php` hands the kernel: it says where the app lives. */
    public static function container(string $root): \Milpa\Container\DIContainer
    {
        $container = new \Milpa\Container\DIContainer();
        $container->registerService(\Milpa\Plugin\Contracts\AppRoot::class, new \Milpa\Plugin\Contracts\AppRoot($root));

        return $container;
    }

    private function admit(string $scope): void
    {
        $app = new Application($this->root, new LabSigner(self::SEAT), new LabSigner(self::SEAT));
        $kernel = (new \ReflectionMethod(Application::class, 'kernel'))->invoke($app);
        $group = CapabilityAdmissions::forRoot($this->root, BuiltCapabilities::of($kernel))->group('Prestamos', $scope);
        self::assertNotNull($group, "Prestamos declares nothing under {$scope}");
        self::assertTrue($this->ledger()->admit(self::SEAT, 'Prestamos', $scope, $group['verbs'], 'key:' . self::HUMAN));
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function asTheSeat(array $arguments): array
    {
        $key = new LabSigner(self::SEAT);

        return $this->dispatch(new Application($this->root, $key, $key), [...$arguments, '--sign']);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function dispatch(Application $app, array $arguments): array
    {
        $token = getenv('MILPA_TOKEN');
        putenv('MILPA_TOKEN');
        ob_start();
        try {
            $exit = $app->run(['coa', ...$arguments]);

            return [$exit, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
            $token === false ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);
        }
    }

    private function ledger(): FileEnrollmentStore
    {
        return new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
    }
}
