<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Identity\ResidentSeat;
use Milpa\AppRuntime\Support\Capabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A command the house hands a person carries the way to reach the house, when the process that serves it says so.
 *
 * In the Desktop the house lives in a container: every line it printed — the seat's `identity:accept`, the hints to
 * continue or to answer — started with `php bin/coa`, and only ran after the person prepended
 * `docker exec milpa-desktop-backend` (greenhouse evidence/1091, E5). The house cannot know it is in a container; the
 * process that serves it does, the same way it is the one that knows the origin a passkey sees (decisions/0534). It
 * declares the prefix in {@see Capabilities::CLI_PREFIX_ENV}, and every command to type is built from {@see
 * Capabilities::cli()}. Undeclared, nothing changes: `php bin/coa `.
 */
#[CoversClass(Capabilities::class)]
#[CoversClass(ResidentSeat::class)]
final class ACommandCarriesTheWayToTheHouseTest extends TestCase
{
    private string|false $before = false;

    protected function setUp(): void
    {
        $this->before = getenv(Capabilities::CLI_PREFIX_ENV);
    }

    protected function tearDown(): void
    {
        $this->before === false ? putenv(Capabilities::CLI_PREFIX_ENV) : putenv(Capabilities::CLI_PREFIX_ENV . '=' . $this->before);
    }

    private function declare(?string $prefix): void
    {
        $prefix === null ? putenv(Capabilities::CLI_PREFIX_ENV) : putenv(Capabilities::CLI_PREFIX_ENV . '=' . $prefix);
    }

    public function testUndeclaredTheCommandIsTheOneItAlwaysWas(): void
    {
        $this->declare(null);

        self::assertSame('php bin/coa ', Capabilities::cli());
        self::assertSame('php bin/coa capabilities:enable ', Capabilities::enableCommand());
    }

    public function testDeclaredEveryCommandStartsWithTheWayIn(): void
    {
        $this->declare('docker exec -it milpa-desktop-backend');

        self::assertSame('docker exec -it milpa-desktop-backend php bin/coa ', Capabilities::cli());
        self::assertSame('docker exec -it milpa-desktop-backend php bin/coa capabilities:enable ', Capabilities::enableCommand());
    }

    public function testTheSeatCommandCarriesIt(): void
    {
        $this->declare('docker exec -it milpa-desktop-backend');
        $root = sys_get_temp_dir() . '/seat-prefix-' . bin2hex(random_bytes(6));
        mkdir($root . '/storage', 0o777, true);

        try {
            $seat = ResidentSeat::invite($root, 'passkey:rod', 'resident');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        self::assertStringStartsWith('docker exec -it milpa-desktop-backend php bin/coa identity:accept --invite=', $seat['command']);
    }

    /**
     * A prefix that would make the printed line do something else is not a way in: the house prints the plain command.
     */
    public function testWhatIsNotAPrefixFallsBackToThePlainCommand(): void
    {
        foreach (['', '   ', "docker exec\nrm -rf /", 'x; rm -rf /', 'a && b', 'a | b', 'a `id`', 'a $(id)', 'a > f', str_repeat('a', 201)] as $prefix) {
            $this->declare($prefix);
            self::assertSame('php bin/coa ', Capabilities::cli(), var_export($prefix, true));
        }
        $this->declare('  docker exec -it milpa-desktop-backend  ');
        self::assertSame('docker exec -it milpa-desktop-backend php bin/coa ', Capabilities::cli(), 'surrounding blanks are not part of it');
    }

    /**
     * 🚨 A GUARD: every command handed to a person goes through `cli()`. The constant stays for what is a NAME, not a line
     * to type — the surface a refusal is recorded under (`php bin/coa mcp`), a banner on stderr — named one by one.
     */
    public function testNoCommandToTypeIsBuiltFromTheConstant(): void
    {
        $allowed = [
            'src/Support/Capabilities.php' => null,
            // the MCP server's stderr banners and the surfaces a refusal is recorded under: names, not lines to type
            'src/Console/Application.php' => ["'milpa · ' . Capabilities::CLI . ", "porLaPuertaSinFirma(Capabilities::CLI . 'mcp'", "porLaPuertaSinFirma(Capabilities::CLI . 'chat'", "\$superficie = Capabilities::CLI . 'shell'"],
        ];
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = 'src/' . substr((string) $file, \strlen(\dirname(__DIR__, 2) . '/src/'));
            if (!str_ends_with($relative, '.php') || (\array_key_exists($relative, $allowed) && $allowed[$relative] === null)) {
                continue;
            }
            foreach (file((string) $file) ?: [] as $n => $line) {
                if (!str_contains($line, 'Capabilities::CLI') && !str_contains($line, 'Capabilities::ENABLE_COMMAND')) {
                    continue;
                }
                $named = false;
                foreach ($allowed[$relative] ?? [] as $ok) {
                    $named = $named || str_contains($line, $ok);
                }
                if (!$named) {
                    $offenders[] = $relative . ':' . ($n + 1) . ': ' . trim($line);
                }
            }
        }

        self::assertSame([], $offenders, "a command to type built from the constant ignores the way in the server declared:\n" . implode("\n", $offenders));
    }
}
