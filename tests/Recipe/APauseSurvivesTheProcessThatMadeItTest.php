<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Recipe;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\GovernedExecutor;
use Milpa\AppRuntime\Recipe\RecipeDriver;
use Milpa\EventStore\FileEventStore;
use PHPUnit\Framework\TestCase;

/**
 * F5 OF `decisions/0223` — THE PAUSE SURVIVES THE PROCESS THAT MADE IT.
 *
 * Every in-process test of the resume shares one store object between the pause and the resume, so it
 * proves the cursor is remembered by the OBJECT, not by the disk. This one does not: a CHILD PHP PROCESS
 * runs the sequence until its consent frontier, persists the pause, and is KILLED (SIGKILL when posix is
 * there, else it exits) — then THIS process, over a fresh store on the same file, resumes it and finishes.
 * The Desktop ceremony (greenhouse evidence/0561) crossed processes the same way — every HTTP request is
 * one — without anybody killing anything on purpose; here the death is deliberate.
 */
final class APauseSurvivesTheProcessThatMadeItTest extends TestCase
{
    public function testAChildProcessPausesAndDiesAndThisOneResumesFromTheFile(): void
    {
        $root = sys_get_temp_dir() . '/milpa-f5-' . bin2hex(random_bytes(4));
        mkdir($root, 0o775, true);
        $ledger = $root . '/agent-sessions.jsonl';
        $script = $root . '/pause-and-die.php';
        file_put_contents($script, self::childScript($ledger));

        $output = [];
        $exit = 0;
        exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
        $said = implode("\n", $output);

        // THE CHILD PERSISTED THE PAUSE BEFORE IT DIED — its own last words, and the file.
        self::assertStringContainsString('PAUSED', $said, $said);
        self::assertFileExists($ledger);
        $fresh = new SessionStore(new FileEventStore($ledger));
        $parked = $fresh->load('recipe:demo');
        self::assertNotNull($parked);
        self::assertNotNull($parked->pausedSequence, 'the pause is a fact of the stream, not a variable of the dead process');
        self::assertSame(1, $parked->pausedSequence->nextIndex, 'one read ran, the mutation is next');
        // And the process is gone — a SIGKILL exits with 137 where posix is there; otherwise it exited.
        self::assertNotSame(0, $exit, 'the child died before returning normally: ' . $exit);

        // THIS PROCESS, A FRESH STORE, THE SAME FILE: the resume finishes the run.
        $resumed = (new RecipeDriver())->resume($fresh, 'recipe:demo', new class () implements GovernedExecutor {
            public function callTool(string $operation, array $arguments): mixed
            {
                return ['ok' => true, 'operation' => $operation];
            }
        });

        self::assertTrue($resumed['ok'] ?? false, json_encode($resumed));
        self::assertTrue($resumed['applied'] ?? false);
        self::assertSame(3, $resumed['executed_count'] ?? null, 'the read the child ran, plus the two it did not');
        self::assertNull((new SessionStore(new FileEventStore($ledger)))->load('recipe:demo')?->pausedSequence, 'resumed, and the file says so');

        array_map('unlink', glob($root . '/*') ?: []);
        rmdir($root);
    }

    /** The child: pause at the consent frontier over the file, say so, and die. */
    private static function childScript(string $ledger): string
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        return <<<PHP
<?php
declare(strict_types=1);
require '{$autoload}';
\$store = new \\Milpa\\Agent\\SessionStore(new \\Milpa\\EventStore\\FileEventStore('{$ledger}'));
\$recipe = \\Milpa\\AppRuntime\\Recipe\\Recipe::fromArray('demo', ['work' => [['op' => 'demo:read'], ['op' => 'demo:mutate'], ['op' => 'demo:read']]]);
\$pausing = new class () implements \\Milpa\\AppRuntime\\Agent\\GovernedExecutor {
    public function callTool(string \$operation, array \$arguments): mixed
    {
        if (\$operation === 'demo:mutate') {
            throw new \\Milpa\\AiGateway\\ToolCallRefusedException("consent needed: {\$operation}");
        }
        return ['ok' => true, 'operation' => \$operation];
    }
};
\$result = (new \\Milpa\\AppRuntime\\Recipe\\RecipeDriver())->apply(
    \$recipe, \$pausing, \$store, 'recipe:demo',
    static fn (): array => ['verdict' => 'unfounded', 'domain' => null],
    static fn (): array => [],
);
echo (\$result['paused'] ?? false) ? "PAUSED\\n" : "NOT PAUSED " . json_encode(\$result) . "\\n";
if (function_exists('posix_kill')) { posix_kill(getmypid(), 9); }
exit(3);
PHP;
    }
}
