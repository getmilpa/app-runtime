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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Config\SecretRedaction;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A SECRET NEVER REACHES THE MODEL THROUGH A TOOL RESULT (greenhouse decisions/0569, evidence/1103).
 *
 * `source_read config/app.php` handed `live.secret` back verbatim, and it travelled to the model endpoint
 * in 15 of 43 requests. Every tool result the resident sees passes through ONE door — the governed
 * executor ({@see ConsentBridge}) — so the house redacts its own secret values there, before the result
 * becomes a `role:tool` message: `source_read`, `source_page`, `config`, and any tool added later, with no
 * per-tool list to keep. The control is that the rest of the result is untouched — the resident keeps
 * reading everything that is not a secret.
 */
final class ToolResultsAreRedactedBeforeTheModelTest extends TestCase
{
    private string $root;

    private const SECRET = 'c0ffeebabe0000111122223333444455556666777788889999aaaabbbbccccdd';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-tool-redaction-' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/.milpa', 0o700, true);
        file_put_contents($this->root . '/.milpa/secrets.json', json_encode(['live' => ['secret' => self::SECRET]]));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.milpa/secrets.json');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    private function registry(mixed $result): ToolRegistry
    {
        $r = new ToolRegistry(new NullLogger());
        $r->register('read', 'Read a file', ['type' => 'object'], static fn (): mixed => $result);

        return $r;
    }

    public function testASecretInAStringResultIsRedactedAtTheBoundary(): void
    {
        $content = "return [\n 'name' => 'My House',\n 'live' => ['secret' => '" . self::SECRET . "'],\n];";
        $bridge = new ConsentBridge($this->registry($content), root: $this->root);

        $out = $bridge->callTool('read', []);

        self::assertIsString($out);
        self::assertStringNotContainsString(self::SECRET, $out, 'the secret must not survive to the model');
        self::assertStringContainsString(SecretRedaction::REDACTED, $out);
        self::assertStringContainsString("'name' => 'My House'", $out, 'the control: the rest of the file still reaches the resident');
    }

    public function testASecretInAnArrayResultIsRedactedKeepingTheShape(): void
    {
        $result = ['ok' => true, 'path' => 'config/app.php', 'content' => "secret: " . self::SECRET, 'total_lines' => 3];
        $bridge = new ConsentBridge($this->registry($result), root: $this->root);

        $out = $bridge->callTool('read', []);

        self::assertIsArray($out);
        self::assertTrue($out['ok'], 'the shape is preserved');
        self::assertSame(3, $out['total_lines']);
        self::assertStringNotContainsString(self::SECRET, $out['content']);
        self::assertStringContainsString(SecretRedaction::REDACTED, $out['content']);
    }

    public function testWithoutAKnownRootTheBoundaryCannotRedactAndSaysSoByItsAbsence(): void
    {
        // Documents the plumbing contract: the boundary redacts only when it knows the house root. The real
        // governed executor always resolves it (AppRoot::of); a bridge built without one does not redact.
        $content = 'secret: ' . self::SECRET;
        $out = (new ConsentBridge($this->registry($content)))->callTool('read', []);

        self::assertSame($content, $out);
    }
}
