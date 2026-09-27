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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The constitution reaches the agent (greenhouse decisions/0485).
 *
 * Measured on fresh cattle (evidence/1019): a rule taught once was kept as a foundation boundary 5 of 5 times,
 * and a later session building the reader's page honoured it 0 of 5 — the prompt never carried the foundation.
 * With it, 4 of 5; the unfounded control stayed red.
 *
 * @guards a founded house's domain, objective and boundaries in the agent's system prompt
 *
 * @refuses to recite a foundation that is absent, a placeholder, invalid or of an unknown schema
 *
 * @subject-in milpa/app-runtime
 */
final class TheConstitutionReachesTheAgentTest extends TestCase
{
    private const BOUNDARY = 'A reader-facing page never reveals editorial state; that is for editors.';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-constitution-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/.milpa', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.milpa/foundation.json');
        @rmdir($this->dir . '/.milpa');
        @rmdir($this->dir);
    }

    public function testAFoundedHouseStatesItsConstitution(): void
    {
        $this->found([
            'schema' => 'milpa.foundation/v1',
            'domain' => 'A blog called «Cuaderno de campo».',
            'objective' => 'Field notes a reader reads.',
            'boundaries' => [self::BOUNDARY, '  ', 7],
            'authorities' => ['product' => 'human', 'destructive_changes' => 'human'],
            'founded_at' => '2026-09-27T01:13:39Z',
        ]);
        $prompt = $this->prompt();

        self::assertStringContainsString('This house is founded. Its constitution binds everything you build here:', $prompt);
        self::assertStringContainsString('Domain: A blog called «Cuaderno de campo».', $prompt);
        self::assertStringContainsString('Objective: Field notes a reader reads.', $prompt);
        self::assertStringContainsString("Boundaries — never cross them:\n- " . self::BOUNDARY, $prompt);
        $section = substr($prompt, (int) strpos($prompt, 'This house is founded.'));
        $section = explode("\n\n", $section)[0];
        self::assertSame(1, substr_count($section, "\n- "), 'a blank or non-string boundary is never recited');
    }

    /** @return iterable<string, array{0: array<string, mixed>|null}> */
    public static function nothingToRecite(): iterable
    {
        yield 'absent' => [null];
        yield 'placeholder' => [['schema' => 'milpa.foundation/v1', 'domain' => null, 'founded_at' => null]];
        yield 'invalid: no authorities' => [['schema' => 'milpa.foundation/v1', 'domain' => 'A blog', 'boundaries' => [self::BOUNDARY]]];
        yield 'unknown schema' => [['schema' => 'milpa.foundation/v9', 'domain' => 'A blog', 'boundaries' => [self::BOUNDARY],
            'authorities' => ['product' => 'human', 'destructive_changes' => 'human']]];
    }

    /**
     * @param array<string, mixed>|null $doc
     *
     * @dataProvider nothingToRecite
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nothingToRecite')]
    public function testNothingIsRecitedWhenTheHouseIsNotFounded(?array $doc): void
    {
        if ($doc !== null) {
            $this->found($doc);
        }
        $prompt = $this->prompt();

        self::assertStringNotContainsString('This house is founded', $prompt);
        self::assertStringNotContainsString(self::BOUNDARY, $prompt);
    }

    /** @param array<string, mixed> $doc */
    private function found(array $doc): void
    {
        file_put_contents($this->dir . '/.milpa/foundation.json', json_encode($doc, JSON_UNESCAPED_UNICODE));
    }

    private function prompt(): string
    {
        $container = new DIContainer();
        $kernel = Kernel::boot(['root' => $this->dir, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]);
        $container->registerService(Kernel::class, $kernel);
        $ops = new AgentOperations($container);
        $m = new \ReflectionMethod($ops, 'systemPrompt');

        return (string) $m->invoke($ops, [], null);
    }
}
