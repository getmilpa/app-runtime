<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\AgentKeys;
use Milpa\AppRuntime\Operations\ConfigOperations;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse evidence/0155 froze before any of this existed.
 *
 * The third case is the decision, not a detail: an unknown key is REPORTED and still written. The
 * runtime speaks only for its own keys — a plugin declares its own and this list cannot know them —
 * so refusing would break a legitimate app to punish a typo, and the write is already governed by
 * consent. Failing open on the write and loud on the report is the opposite of this house's usual
 * reflex, which is why it carries its reason rather than being assumed.
 */
final class AgentKeysTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/keys-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.milpa', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.milpa/agent.json');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    /** 1 · reading names the keys that exist, each with its type and what it decides. */
    public function testTheReadNamesEveryKeyWithItsTypeAndPurpose(): void
    {
        /** @var array{keys: array<string, array{type: string, does: string}>} $r */
        $r = ($this->op('config')->handler)([]);

        self::assertArrayHasKey('agent.instructions', $r['keys']);
        self::assertArrayHasKey('type', $r['keys']['agent.instructions']);
        self::assertNotSame('', $r['keys']['agent.instructions']['does']);
        self::assertCount(19, $r['keys']);
        self::assertArrayHasKey('agent.trialWorkspace', $r['keys'], 'the trial leaf is one of the keys a human can set');
    }

    /**
     * THE PATH IT REPORTS HAS TO BE A PATH THAT EXISTS.
     *
     * Measured in a real chat session on cattle: the human was told the change was saved to
     * `/.milpa/agent.json`, which sends them to the filesystem root. The write was correct — the file
     * is under the app — and only the sentence was wrong, which is the worst kind of wrong: it looks
     * like help and costs a search (greenhouse evidence/0199).
     */
    public function testItReportsAPathThatExists(): void
    {
        /** @var array{written_to: string} $r */
        $r = ($this->op('config:set')->handler)(['key' => 'agent.treeBudget', 'value' => 7]);

        self::assertStringStartsNotWith('/', $r['written_to'], 'no es absoluta, así que no se anuncia como si lo fuera');
        self::assertFileExists($this->root . '/' . $r['written_to'], 'lo que dice tiene que estar ahí');
    }

    /** 2 · a known key is written and nothing extra is said about it. */
    public function testAKnownKeyIsWrittenWithoutComment(): void
    {
        /** @var array{ok: bool, unknown_key?: bool} $r */
        $r = ($this->op('config:set')->handler)(['key' => 'agent.instructions', 'value' => 'x']);

        self::assertTrue($r['ok']);
        self::assertArrayNotHasKey('unknown_key', $r);
    }

    /**
     * 3 · THE DECISION: an unknown key is written AND reported as one the runtime does not declare.
     *
     * Both halves matter. Reporting without writing would refuse a plugin's own key; writing without
     * reporting is the silence this whole slice exists to end.
     */
    public function testAnUnknownKeyIsWrittenAndSaidToBeUnknown(): void
    {
        /** @var array{ok: bool, unknown_key: bool} $r */
        $r = ($this->op('config:set')->handler)(['key' => 'agent.noSuchKnob', 'value' => 1]);

        self::assertTrue($r['ok'], 'a key this runtime does not declare must still be writable');
        self::assertTrue($r['unknown_key']);
        self::assertSame(
            ['noSuchKnob' => 1],
            json_decode((string) file_get_contents($this->root . '/.milpa/agent.json'), true)['agent'] ?? [],
        );
    }

    /** 4 · the list is not empty and every entry carries both fields — a half-declared key teaches nothing. */
    public function testEveryDeclaredKeyCarriesATypeAndAPurpose(): void
    {
        foreach (AgentKeys::todas() as $llave => $ficha) {
            self::assertNotSame('', $ficha['type'], "«{$llave}» no declara tipo");
            self::assertNotSame('', $ficha['does'], "«{$llave}» no dice para qué sirve");
            self::assertStringStartsWith('agent.', $llave);
        }
    }

    private function op(string $nombre): Operation
    {
        foreach (ConfigOperations::para($this->root)->operations() as $op) {
            if ($op->name === $nombre) {
                return $op;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }
}
