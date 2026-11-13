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

use Milpa\AppRuntime\Config\SecretRedaction;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE OTHER SECRET FILES AND THE KNOWN ENVIRONMENT CARRY IS KEPT OUT OF WHAT A TOOL RETURNS (greenhouse
 * decisions/0628).
 *
 * A route the agent wrote returned the house's `.env`, its `auth.json` and a variable of its launcher, and the
 * observation of the live house handed them to the model: none of them was in the overlay. Each case has its control:
 * the same text, without the file or the variable, keeps the value. It is a defence, and its limit is pinned too: a
 * variable the house cannot tell from a setting passes.
 */
final class TheSecretFilesAndTheKnownEnvironmentAreKeptOutTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> what each variable a test set was before it */
    private array $variables = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-secret-files-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->variables as $variable => $before) {
            putenv($before === false ? $variable : $variable . '=' . $before);
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheCredentialsOfTheEnvFileAreKeptOut(): void
    {
        $text = 'db=pa55-w0rd-of-the-db quoted=quoted secret here exported=exported-1234 commented=commented-5678 '
            . 'token=cnyenvfile-98a8883c255fb363c985 url=s3cret-in-url';
        self::assertSame($text, SecretRedaction::inText($text, $this->root), 'the control: with no .env the values pass');

        file_put_contents($this->root . '/.env', implode("\n", [
            '# a comment=not-a-value-at-all',
            'DB_PASSWORD=pa55-w0rd-of-the-db',
            'MAIL_SECRET="quoted secret here"',
            'export API_TOKEN=exported-1234',
            'APP_KEY=commented-5678 # why',
            'LAB_ENVFILE_CANARY=cnyenvfile-98a8883c255fb363c985',
            'DATABASE_URL=mysql://milpa:s3cret-in-url@db:3306/milpa',
        ]) . "\n");

        $redacted = SecretRedaction::inText($text, $this->root);
        foreach (['pa55-w0rd-of-the-db', 'quoted secret here', 'exported-1234', 'commented-5678', 'cnyenvfile-98a8883c255fb363c985', 's3cret-in-url'] as $value) {
            self::assertStringNotContainsString($value, $redacted);
        }
    }

    public function testTheSettingsOfTheEnvFileStayReadable(): void
    {
        file_put_contents($this->root . '/.env', implode("\n", [
            'APP_ENV=production',
            'DB_DATABASE=database',
            'APP_URL=http://localhost:8000',
            'APP_TIMEZONE=America/Mexico_City',
            'STORAGE_PATH=/srv/house/storage/a1b2c3d4e5',
        ]) . "\n");
        $text = 'production database http://localhost:8000 America/Mexico_City /srv/house/storage/a1b2c3d4e5';

        self::assertSame($text, SecretRedaction::inText($text, $this->root), 'a setting is not a credential');
    }

    public function testTheShellsWorkingDirectoryIsNotACredential(): void
    {
        $this->env('PWD', '/srv/a-house-directory');

        self::assertSame('/srv/a-house-directory/src/A.php', SecretRedaction::inText('/srv/a-house-directory/src/A.php', null));
    }

    public function testATemplateOfTheEnvFileIsNotReadAsSecrets(): void
    {
        file_put_contents($this->root . '/.env.example', "DB_PASSWORD=change-me-please\n");

        self::assertSame('change-me-please', SecretRedaction::inText('change-me-please', $this->root));
    }

    public function testEveryStringOfAuthJsonIsKeptOut(): void
    {
        $text = 'token ghp_aaaaaaaaaaaaaaaa and password lab-auth-password-1';
        self::assertSame($text, SecretRedaction::inText($text, $this->root), 'the control');

        file_put_contents($this->root . '/auth.json', (string) json_encode([
            'github-oauth' => ['github.com' => 'ghp_aaaaaaaaaaaaaaaa'],
            'http-basic' => ['lab.invalid' => ['username' => 'lab', 'password' => 'lab-auth-password-1']],
        ]));

        $redacted = SecretRedaction::inText($text, $this->root);
        self::assertStringNotContainsString('ghp_aaaaaaaaaaaaaaaa', $redacted);
        self::assertStringNotContainsString('lab-auth-password-1', $redacted);
    }

    public function testAVariableNamedAsACredentialIsKeptOut(): void
    {
        $this->env('MILPA_TEST_DB_PASSWORD', 'env-password-0628');
        $this->env('MILPA_TEST_LAUNCHER_CANARY', 'launcher-value-0628');

        $redacted = SecretRedaction::inText('a=env-password-0628 b=launcher-value-0628', null);

        self::assertStringNotContainsString('env-password-0628', $redacted);
        self::assertStringContainsString('launcher-value-0628', $redacted, 'the limit, stated: a variable not named as a credential passes');
    }

    public function testThePasswordOfAUrlInTheEnvironmentIsKeptOut(): void
    {
        $this->env('MILPA_TEST_DATABASE_URL', 'mysql://milpa:url-password-0628@db:3306/milpa');

        $redacted = SecretRedaction::inText('password is url-password-0628', null);

        self::assertStringNotContainsString('url-password-0628', $redacted);
    }

    public function testASecretIsKeptOutInTheFormsItTakesOnceEncoded(): void
    {
        file_put_contents($this->root . '/.env', "APP_SECRET=a/b\"c<d>&e f\n");
        $secret = 'a/b"c<d>&e f';

        foreach ([
            'json' => (string) json_encode(['v' => $secret]),
            'url' => 'q=' . rawurlencode($secret),
            'html' => htmlspecialchars($secret, \ENT_QUOTES),
            'plain' => $secret,
        ] as $form => $text) {
            $redacted = SecretRedaction::inText($text, $this->root);
            self::assertStringContainsString(SecretRedaction::REDACTED, $redacted, $form);
            self::assertStringNotContainsString('c<d>', html_entity_decode(rawurldecode((string) (json_decode($redacted, true)['v'] ?? $redacted))), $form);
        }
    }

    public function testAnEditedEnvFileIsReadAgain(): void
    {
        file_put_contents($this->root . '/.env', "TOKEN=first-value-0628\n");
        self::assertStringNotContainsString('first-value-0628', SecretRedaction::inText('first-value-0628', $this->root));

        // The same length, in the same second: the file is read again by what it says.
        file_put_contents($this->root . '/.env', "TOKEN=other-value-0628\n");

        self::assertStringNotContainsString('other-value-0628', SecretRedaction::inText('other-value-0628', $this->root));
    }

    private function env(string $name, string $value): void
    {
        $this->variables[$name] ??= getenv($name);
        putenv($name . '=' . $value);
    }
}
