<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\Command\Effect\{Authority, EffectProfile, Externality, Mutation, Reversibility, Subject};
use Milpa\Command\Operation;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Trial feedback locates a partial without changing producer output or applying its bytes. */
final class TrialPartialContinuationTest extends TestCase
{
    private string $root;
    private string $bwrap;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-partial-note-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Owned/Services', 0755, true);
        file_put_contents($this->root . '/src/Plugins/Owned/Services/Renderer.php', '<?php // Live source');
        $this->bwrap = $this->root . '-bwrap';
        file_put_contents(
            $this->bwrap,
            <<<'SH'
#!/bin/sh
while [ "$1" != "--" ] && [ $# -gt 0 ]; do shift; done
shift
exec "$@"
SH
        );
        chmod($this->bwrap, 0755);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        unlink($this->bwrap);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cases(): iterable
    {
        foreach (['start', 'append', 'amend', 'reset'] as $mode) {
            yield $mode => [$mode, true];
        }
        foreach (['other-operation', 'inline', 'finish', 'empty-partial', 'missing-partial', 'verified',
            'wrong-path', 'wrong-file', 'missing-file', 'bad-hash', 'missing-hash', 'different-hash',
            'no-change', 'extra-change', 'deleted', 'missing-output'] as $case) {
            yield $case => [$case, false];
        }
    }

    #[DataProvider('cases')]
    public function testOnlyAnUnambiguousPartialGetsTheContinuationNote(string $case, bool $expected): void
    {
        $file = 'src/Plugins/Owned/Services/Renderer.php';
        $staging = $file . '.milpa-part';
        if (in_array($case, ['append', 'amend', 'reset', 'deleted'], true)) {
            file_put_contents($this->root . '/' . $staging, 'Earlier part');
        }
        $before = is_file($this->root . '/' . $staging) ? file_get_contents($this->root . '/' . $staging) : null;
        $operation = $case === 'other-operation' ? 'edit' : 'implement';
        $input = ['fixture' => $case, 'mode' => in_array($case, ['append', 'amend', 'reset', 'finish'], true) ? $case : 'start'];
        if ($case === 'inline') {
            unset($input['mode']);
        }
        $registry = $this->registry($operation);
        $result = $registry->call($operation, $input);

        self::assertTrue($result->success, (string) $result->error);
        self::assertTrue($result->data['ran_in_trial']);
        self::assertFalse($result->data['applied']);
        $note = $result->data['note'];
        self::assertSame($expected, str_starts_with($note, 'The accepted part exists only in this trial.'));
        self::assertSame('<?php // Live source', file_get_contents($this->root . '/' . $file));
        self::assertSame($before, is_file($this->root . '/' . $staging) ? file_get_contents($this->root . '/' . $staging) : null);
        if ($case !== 'no-change') {
            self::assertSame(['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $result->data['workspace']]], $result->data['to_apply']);
            self::assertSame('sandbox:discard', $result->data['to_discard']['operation']);
        } else {
            self::assertArrayNotHasKey('to_apply', $result->data);
        }
        if ($expected) {
            self::assertSame(['ok' => true, 'file' => $file, 'staging' => $staging,
                'sha256' => hash('sha256', "<?php // Partial renderer\n"), 'partial' => 'Producer says append next'], $result->data['output']);
            self::assertStringContainsString('Before another reset, append, amend or finish', $note);
            self::assertStringContainsString('check that its domain result succeeded', $note);
            self::assertStringContainsString('unchanged and unverified', $note);
            self::assertStringContainsString('producer-verified candidates', $note);
            self::assertArrayNotHasKey('verified', $result->data['output']);
            self::assertArrayNotHasKey('authorization', $result->data);
            self::assertSame($result->data['output']['sha256'], $result->meta['trial']['report'][$staging]['sha256']);
        }
    }

    public function testAFailedProducerNeverReceivesTheSuccessfulPartialNote(): void
    {
        $result = $this->registry('implement')->call('implement', ['fixture' => 'failed', 'mode' => 'start']);
        self::assertFalse($result->success);
        self::assertSame('Producer refused the part', $result->error);
        self::assertArrayNotHasKey('note', $result->data);
        self::assertArrayNotHasKey('to_apply', $result->data);
        self::assertFileDoesNotExist($this->root . '/src/Plugins/Owned/Services/Renderer.php.milpa-part');
    }

    private function registry(string $name): TrialAwareRegistry
    {
        $inner = new ToolRegistry(new NullLogger());
        $inner->register($name, 'Fixture authoring', ['type' => 'object'], static function (): never {
            throw new \RuntimeException('The host handler must not run');
        });
        $operation = new Operation(
            name: $name,
            description: 'Fixture authoring',
            handler: static fn (): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Compensatable,
                authority: Authority::WriteAsUser,
                subject: Subject::Executable
            )
        );
        $router = new TrialRouter($this->root, new TrialRunner(bwrap: $this->bwrap), dirname(__DIR__) . '/Fixtures/trial-partial-runner.php');

        return new TrialAwareRegistry($inner, $router, [$operation]);
    }
}
