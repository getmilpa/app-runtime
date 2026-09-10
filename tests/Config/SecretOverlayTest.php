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

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\MachineOverlay;
use Milpa\AppRuntime\Config\SecretOverlay;
use PHPUnit\Framework\TestCase;

/**
 * A SECRET HAS SOMEWHERE TO LIVE, and no reader can print it.
 *
 * Measured on `milpa/framework`, the live template: it ships no `.env`, loads no `.env`, and does not
 * ignore one. Its config homes are `config/app.php` and `.milpa/agent.json`, both committed. So an
 * API key had two destinations and both were wrong — a file that goes to git, or a file nothing reads
 * (greenhouse decisions/0267).
 */
final class SecretOverlayTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-secret-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.milpa', 0o700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . SecretOverlay::RUTA);
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    /**
     * 🚨 THE PROPERTY THIS CLASS EXISTS FOR: no public method hands a value back.
     *
     * `declared()` answers WHICH paths hold a secret; nothing answers what they hold. A surface that
     * can print a key is a surface that will, and then the key lives in a rendered page, a log line
     * and a screenshot. Its only consumer is configuration, where the code that needs it already
     * looks.
     *
     * This asserts the SHAPE of the class rather than a behaviour, deliberately: a future accessor
     * added «for the settings screen» fails here before it can be called once.
     */
    public function testNoReaderCanEverPrintASecret(): void
    {
        self::assertSame(
            ['any', 'declared', 'sobre'],
            self::publicSurface(),
            'the only ways out are «which paths», «is there any», and «into configuration»',
        );

        $this->write(['agent' => ['apiKey' => 'sk-never-printed']]);

        self::assertSame(['agent.apiKey'], SecretOverlay::declared($this->root), 'paths, not values');
        foreach (SecretOverlay::declared($this->root) as $path) {
            self::assertStringNotContainsString('sk-never-printed', $path);
        }
    }

    /** The value reaches configuration, which is the one place it is supposed to reach. */
    public function testTheValueReachesConfigurationAndTheHumansNeighboursSurvive(): void
    {
        $this->write(['agent' => ['baseUrl' => 'https://provider.private']]);

        $merged = SecretOverlay::sobre(['agent' => ['model' => 'm', 'baseUrl' => 'https://declared-in-the-open']], $this->root);

        self::assertSame('https://provider.private', $merged['agent']['baseUrl'], 'the secret is the most specific declaration');
        self::assertSame('m', $merged['agent']['model'], 'and writing one key erases no neighbour');
    }

    /**
     * ABSENT IS THE ORDINARY CASE, not a failure — most apps hold no secret.
     *
     * And an unreadable file returns the configuration untouched, for the reason its sibling gives:
     * the alternative turns a stray comma into an app with no configuration, in silence.
     */
    public function testAbsentAndUnreadableBothLeaveConfigurationAlone(): void
    {
        $declared = ['agent' => ['model' => 'm']];

        self::assertSame($declared, SecretOverlay::sobre($declared, $this->root), 'absent');
        self::assertSame([], SecretOverlay::declared($this->root));
        self::assertFalse(SecretOverlay::any($this->root));

        file_put_contents($this->root . SecretOverlay::RUTA, '{"agent": {,}');
        self::assertSame($declared, SecretOverlay::sobre($declared, $this->root), 'unreadable');
        self::assertSame([], SecretOverlay::declared($this->root));
    }

    /**
     * IT IS A SEPARATE FILE FROM THE PUBLIC OVERLAY, and that is the point rather than an accident.
     *
     * «What the machine wrote» belongs beside the constitution and travels with the repository — the
     * acta trail. «What the machine wrote and must never leave this machine» cannot travel at all.
     * One file with a flag would make a `.gitignore` decision depend on reading its contents, and the
     * first person to get that wrong would get it wrong with a key inside.
     */
    public function testItIsNotTheSameFileAsTheOverlayThatTravels(): void
    {
        self::assertNotSame(MachineOverlay::RUTA, SecretOverlay::RUTA);
        self::assertSame(SecretOverlay::RUTA, SecretOverlay::IGNORE_LINE, 'the path an app must ignore IS the path it writes');
        self::assertStringContainsString('secret', SecretOverlay::RUTA, 'the name says what it is in a diff');
    }

    /** Nested paths read the way `Config::get()` asks for them, so a report can be copied verbatim. */
    public function testPathsAreDottedAndSorted(): void
    {
        $this->write([
            'mercure' => ['subscriber_key' => 's', 'publisher_key' => 'p'],
            'agent' => ['apiKey' => 'k'],
        ]);

        self::assertSame(
            ['agent.apiKey', 'mercure.publisher_key', 'mercure.subscriber_key'],
            SecretOverlay::declared($this->root),
        );
    }

    /** A list value is one secret, not a path per element — an allow-list is a value. */
    public function testAListIsOneSecretAndNotOnePerElement(): void
    {
        $this->write(['agent' => ['allowed' => ['a', 'b', 'c']]]);

        self::assertSame(['agent.allowed'], SecretOverlay::declared($this->root));
    }

    /** @param array<string, mixed> $tree */
    private function write(array $tree): void
    {
        file_put_contents($this->root . SecretOverlay::RUTA, (string) json_encode($tree));
    }

    /** @return list<string> */
    private static function publicSurface(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(SecretOverlay::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $out[] = $m->getName();
        }
        sort($out);

        return $out;
    }
}
