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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Enabling identity on a house where `milpa/auth` is ALREADY installed declares the relying party too.
 *
 * The relying party (`passkey.rpId`) was written only when identity ARRIVED with the enable. On a house
 * where composer had landed `milpa/auth` beforehand — an image that bakes its packages from a lock, a
 * `composer require` typed by hand — the enable took the «installed but not declared» branch, which
 * wrote the plugin and never the relying party: the door stayed unconfigured and the invitation the
 * panel printed answered 404 (greenhouse evidence/1041). The image had to declare it by hand
 * (docker/declare-baked.php). Now the same idempotent writer runs on both branches, and the reader that
 * says what is missing sees it.
 *
 * @guards capabilities:enable identity over an installed milpa/auth, and the reader recipe:plan asks
 *
 * @fires  on every capabilities:enable, recipe:apply and recipe:plan
 *
 * @refuses to overwrite a relying party the app already declares
 *
 * @subject-in milpa/app-runtime
 */
#[CoversClass(Capabilities::class)]
final class EnablingInstalledIdentityDeclaresTheRelyingPartyTest extends TestCase
{
    private const APP = <<<'PHP_'
        <?php

        declare(strict_types=1);

        return [
            'app' => ['name' => 'baked-house', 'debug' => true],
        ];

        PHP_;

    private string $root = '';

    private string $vendor = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-baked-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/operations.php', "<?php\n\nreturn [\n];\n");
        // THE BAKED SHAPE: the door's plugin is already named (the image declared it), the relying party
        // is not — which is exactly what the «installed but not declared» branch used to call done.
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\nreturn [\n    \\" . PasskeyPlugin::class . "::class,\n];\n");
        file_put_contents($this->root . '/config/app.php', self::APP);

        $this->vendor = $this->root . '/vendor';
        mkdir($this->vendor . '/composer', 0o775, true);
        file_put_contents($this->vendor . '/composer/installed.json', json_encode(['packages' => [
            ['name' => 'milpa/auth', 'version' => '1.0.0', 'extra' => ['milpa' => ['capability' => ['id' => 'identity', 'title' => 'identity']]]],
            ['name' => 'milpa/devtools', 'version' => '1.0.0', 'extra' => ['milpa' => ['capability' => ['id' => 'devtools', 'title' => 'devtools']]]],
        ]], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function enabling_installed_identity_declares_the_relying_party_and_runs_no_composer(): void
    {
        $answer = Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok'], json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertSame('milpa/auth', $answer['capability']);
        self::assertSame(['rpId' => 'localhost', 'written' => true, 'file' => 'config/app.php'], $answer['relying_party'] ?? null);
        self::assertStringNotContainsString('nothing to do', (string) $answer['hint'], 'it does not report done over a door with no relying party');
        self::assertStringContainsString('first_passkey', (string) $answer['hint'], 'the hint is the door\'s, like a fresh enable\'s');

        // VERIFIED BY LOADING: the declaration is what PHP returns from the file, and the rest is untouched.
        $config = (fn (): mixed => include $this->root . '/config/app.php')();
        self::assertIsArray($config);
        self::assertSame('localhost', $config['passkey']['rpId']);
        self::assertSame(['http://localhost:8000'], $config['passkey']['origins'], 'the origins the door refuses to boot without');
        self::assertSame('baked-house', $config['app']['name']);
    }

    #[Test]
    public function enabling_it_twice_is_a_no_op(): void
    {
        Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);
        $app = (string) file_get_contents($this->root . '/config/app.php');
        $plugins = (string) file_get_contents($this->root . '/config/plugins.php');

        $again = Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($again['ok']);
        self::assertStringContainsString('nothing to do', (string) $again['hint'], 'the second enable finds it done — and says so');
        self::assertArrayNotHasKey('relying_party', $again);
        self::assertStringEqualsFile($this->root . '/config/app.php', $app);
        self::assertStringEqualsFile($this->root . '/config/plugins.php', $plugins);
        self::assertSame(1, substr_count($app, "'passkey'"), 'one declaration, not two');
    }

    #[Test]
    public function an_explicit_relying_party_is_never_overwritten(): void
    {
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn ['passkey' => ['rpId' => 'notes.example', 'origins' => ['https://notes.example']], 'app' => ['name' => 'x']];\n");
        $before = (string) file_get_contents($this->root . '/config/app.php');

        self::assertSame([], Capabilities::unwired($this->root, $this->manifest('milpa/auth')), 'an explicit value is declared');
        $answer = Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok']);
        self::assertStringContainsString('nothing to do', (string) $answer['hint']);
        self::assertStringEqualsFile($this->root . '/config/app.php', $before);
    }

    #[Test]
    public function the_reader_sees_the_missing_relying_party_and_a_dry_run_writes_nothing(): void
    {
        self::assertSame(['passkey.rpId'], Capabilities::unwired($this->root, $this->manifest('milpa/auth')));

        $dry = Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), dryRun: true, root: $this->root);

        self::assertTrue($dry['ok']);
        self::assertSame(['passkey.rpId'], $dry['would_declare'] ?? null);
        self::assertStringEqualsFile($this->root . '/config/app.php', self::APP);

        // THE POSITIVE CONTROL: once declared, the same reader reports nothing — or the gap would read as
        // the permanent state and every recipe would enable identity forever.
        Capabilities::declareRelyingParty($this->root);
        self::assertSame([], Capabilities::unwired($this->root, $this->manifest('milpa/auth')));
    }

    #[Test]
    public function a_capability_without_a_door_never_touches_the_app_config(): void
    {
        // devtools is installed on the same house: enabling it declares nothing of identity's.
        self::assertSame([], Capabilities::unwired($this->root, $this->manifest('milpa/devtools')));

        $answer = Capabilities::install('devtools', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok']);
        self::assertArrayNotHasKey('relying_party', $answer);
        self::assertStringEqualsFile($this->root . '/config/app.php', self::APP);
    }

    #[Test]
    public function a_house_without_an_app_config_is_not_told_to_declare_one(): void
    {
        // The writers' rule: nothing is declared into a file that does not exist, so the reader does not
        // report it missing either — or enable would answer «declared» over a write it could not make.
        unlink($this->root . '/config/app.php');

        self::assertSame([], Capabilities::unwired($this->root, $this->manifest('milpa/auth')));
        $answer = Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);
        self::assertTrue($answer['ok']);
        self::assertStringContainsString('nothing to do', (string) $answer['hint']);
        self::assertFileDoesNotExist($this->root . '/config/app.php');
    }

    /** What `capabilities:enable identity` wrote into config/app.php on 0.200.x: the rpId, and no origins. */
    private const FROM_BEFORE_THE_ORIGINS = <<<'PHP_'
        <?php

        declare(strict_types=1);

        return [
            'app' => ['name' => 'house-from-0.200', 'debug' => false],

            // Declared by `capabilities:enable identity`: the relying-party id passkey assertions bind to.
            // It must equal the host the browser uses (`php bin/coa serve` answers at http://localhost:…). Change it
            // to your domain before enrolling anyone there.
            'passkey' => ['rpId' => 'localhost'],
        ];

        PHP_;

    /**
     * A HOUSE FROM BEFORE THE ORIGINS GETS THEM WRITTEN (greenhouse decisions/0533). Its door boots on the
     * origins derived in memory; the same enable that declares a new house's relying party completes this
     * one on disk — where the person reads and changes it — with the value a new house gets.
     */
    #[Test]
    public function enabling_identity_completes_a_relying_party_declared_without_its_origins(): void
    {
        file_put_contents($this->root . '/config/app.php', self::FROM_BEFORE_THE_ORIGINS);
        self::assertSame(['passkey.origins'], Capabilities::unwired($this->root, $this->manifest('milpa/auth')), 'the reader sees what is missing');

        $answer = Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);

        self::assertTrue($answer['ok'], json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertSame(['rpId' => 'localhost', 'written' => true, 'file' => 'config/app.php', 'origins' => ['http://localhost:8000']], $answer['relying_party'] ?? null);
        self::assertStringNotContainsString('nothing to do', (string) $answer['hint']);
        self::assertStringContainsString('passkey.origins is declared now', (string) $answer['hint']);
        self::assertStringNotContainsString('first_passkey', (string) $answer['hint'], 'the door was open already: no invitation to chase');

        $config = (fn (): mixed => include $this->root . '/config/app.php')();
        self::assertIsArray($config);
        self::assertSame(['rpId' => 'localhost', 'origins' => ['http://localhost:8000']], $config['passkey']);
        self::assertSame(['name' => 'house-from-0.200', 'debug' => false], $config['app'], 'the rest of the file is untouched');
        self::assertSame(1, substr_count((string) file_get_contents($this->root . '/config/app.php'), "'passkey'"), 'completed in place, not declared twice');
        self::assertSame([], Capabilities::unwired($this->root, $this->manifest('milpa/auth')));

        // And once is enough: the second enable finds it done.
        $again = Capabilities::install('identity', $this->vendor, $this->composerThatMustNotRun(), root: $this->root);
        self::assertStringContainsString('nothing to do', (string) $again['hint']);
    }

    #[Test]
    public function the_completion_keeps_what_else_the_person_declared_beside_the_rp_id(): void
    {
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn [\n    'passkey' => [\n        'rpId' => 'notes.example',\n        'ttl' => 10800,\n    ],\n];\n");

        $answer = Capabilities::declareRelyingParty($this->root);

        self::assertSame(['rpId' => 'notes.example', 'written' => true, 'file' => 'config/app.php', 'origins' => ['https://notes.example']], $answer);
        $config = (fn (): mixed => include $this->root . '/config/app.php')();
        self::assertIsArray($config);
        self::assertSame(['rpId' => 'notes.example', 'origins' => ['https://notes.example'], 'ttl' => 10800], $config['passkey']);
    }

    /** An rpId the writer cannot find as a literal is not guessed at: the file stays, and the answer says what to write. */
    #[Test]
    public function a_relying_party_it_cannot_complete_in_place_is_left_as_it_is_and_named(): void
    {
        // The one literal is in a comment: written there, the file would load back without origins — so it is not kept.
        $src = "<?php\n\n// was: 'rpId' => 'notes.example'\n\$host = 'notes.example';\n\nreturn ['passkey' => ['rpId' => \$host]];\n";
        file_put_contents($this->root . '/config/app.php', $src);

        $answer = Capabilities::declareRelyingParty($this->root);

        self::assertIsArray($answer);
        self::assertFalse($answer['written']);
        self::assertStringContainsString("'origins' => ['https://notes.example']", (string) ($answer['error'] ?? ''));
        self::assertStringEqualsFile($this->root . '/config/app.php', $src);
    }

    /** @return array<string, mixed> */
    private function manifest(string $package): array
    {
        return Capabilities::declaredBy($this->vendor)[$package] ?? [];
    }

    private function composerThatMustNotRun(): callable
    {
        return static function (string $command): array {
            self::fail("composer must not run for an installed package, and was asked: {$command}");
        };
    }
}
