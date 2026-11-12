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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Web\LivePlugin;
use Milpa\Container\DIContainer;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\Contracts\Security\CsrfGuardInterface;
use Milpa\Live\Security\HmacCsrfGuard;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * A CONFINED PROCESS BOOTS THE LIVE WIRE WITH A KEY OF ITS OWN (greenhouse evidence/1211, H1; Rod, 2026-11-11).
 *
 * Measured on cattle: in every house started from the image, `screen:declare` was refused inside a trial — the live
 * secret lives in the secrets overlay, and a trial neither copies nor reads it (it is masked from code nobody applied).
 * Inside a confined process the wire now boots with a key of that process's own, never the house's; outside one the
 * house still fails closed, and the refusal sends the secret to the overlay, not to a versioned config file.
 */
final class AConfinedProcessBootsTheLiveWireWithAKeyOfItsOwnTest extends TestCase
{
    private string $dir = '';

    private string|false $trial = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-live-confined-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        $this->trial = getenv(TrialRunner::INSIDE_TRIAL_ENV);
    }

    protected function tearDown(): void
    {
        putenv($this->trial === false ? TrialRunner::INSIDE_TRIAL_ENV : TrialRunner::INSIDE_TRIAL_ENV . '=' . $this->trial);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testInsideAConfinedProcessTheWireMountsWithoutTheHousesSecret(): void
    {
        putenv(TrialRunner::INSIDE_TRIAL_ENV . '=1');
        $container = $this->container();
        $plugin = new LivePlugin($container);
        $plugin->boot();

        self::assertNotSame([], $plugin->routes(), 'the wire mounted: the screen operations have their registry');
        self::assertTrue($container->has(ComponentRegistryInterface::class));
        self::assertNotNull($plugin->bootFor());
    }

    public function testOutsideAConfinedProcessTheHouseStillFailsClosedAndSaysWhereTheSecretGoes(): void
    {
        putenv(TrialRunner::INSIDE_TRIAL_ENV);
        $plugin = new LivePlugin($this->container());
        $plugin->boot();

        self::assertSame([], $plugin->routes(), 'no secret, no routes: a key nobody chose is not minted for the house');

        $declare = null;
        foreach ($plugin->operations() as $operation) {
            if ($operation->name === 'screen:declare') {
                $declare = $operation;
            }
        }
        self::assertNotNull($declare);
        $refused = ($declare->handler)(['name' => 'blog', 'type' => 'data-table', 'columns' => ['title']]);

        self::assertFalse($refused['ok']);
        self::assertStringContainsString('provider:declare --key=live.secret --file=<a file outside this app> --sign', (string) $refused['error'], 'the overlay, by a command that runs as typed');
        self::assertStringContainsString('Not in config/app.php', (string) $refused['error']);
    }

    public function testTheHousesOwnSecretSignsAlsoInAConfinedProcessThatCanReadIt(): void
    {
        $secret = str_repeat('h', 32);
        foreach ([false, true] as $confined) {
            putenv($confined ? TrialRunner::INSIDE_TRIAL_ENV . '=1' : TrialRunner::INSIDE_TRIAL_ENV);
            $container = $this->container(['secret' => $secret]);
            (new LivePlugin($container))->boot();
            $token = $container->get(CsrfGuardInterface::class)->issueToken('session', '/live');

            self::assertTrue((new HmacCsrfGuard($secret))->verifyToken($token, 'session', '/live'), $confined ? 'a key of its own only when it cannot read the house\'s' : 'the house signs with its own');
            self::assertFalse((new HmacCsrfGuard(str_repeat('o', 32)))->verifyToken($token, 'session', '/live'));
        }
    }

    public function testSixteenBytesAreASecret(): void
    {
        putenv(TrialRunner::INSIDE_TRIAL_ENV);
        $plugin = new LivePlugin($this->container(['secret' => str_repeat('s', 16)]));
        $plugin->boot();

        self::assertNotSame([], $plugin->routes());
    }

    public function testEachConfinedProcessMintsAStrongKeyOfItsOwn(): void
    {
        $script = $this->dir . '/key.php';
        file_put_contents($script, '<?php require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
$c = new Milpa\Container\DIContainer();
$c->registerService(Milpa\Runtime\Config::class, new Milpa\Runtime\Config(["live" => ["nonce_path" => ' . var_export($this->dir . '/n.json', true) . ']]));
(new Milpa\AppRuntime\Web\LivePlugin($c))->boot();
echo (string) (new ReflectionProperty(Milpa\AppRuntime\Web\LivePlugin::class, "confinedKey"))->getValue();');
        $keys = [];
        foreach ([1, 2] as $_) {
            $keys[] = (string) shell_exec(TrialRunner::INSIDE_TRIAL_ENV . '=1 ' . escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script));
        }

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $keys[0], '32 random bytes');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $keys[1]);
        self::assertNotSame($keys[0], $keys[1], 'each process its own: nothing it signs is anyone else\'s');
    }

    /** @param array<string, mixed> $live */
    private function container(array $live = []): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['live' => $live + [
            'nonce_path' => $this->dir . '/nonces.json',
            'screens_path' => $this->dir . '/screens.json',
        ]]));

        return $c;
    }
}
