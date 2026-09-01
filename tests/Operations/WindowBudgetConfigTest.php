<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Config\AgentKeys;
use Milpa\AppRuntime\Operations\AgentOperations;
use PHPUnit\Framework\TestCase;

/**
 * The context the app declares is the budget the Compactor receives — and silence changes nothing.
 *
 * Greenhouse evidence/0443 measured the failure this key exists to prevent: a session against a
 * 32,768-token model re-entered at 35.6k tokens, because only the turn tail had a budget and the
 * summary's system side grew unbounded. `agent.contextTokens` (config first, then the
 * MILPA_AGENT_CONTEXT_TOKENS environment fallback — {@see \Milpa\AppRuntime\Config\AgentEndpoint}
 * owns that precedence) hands the whole declared context to the `Compactor`, which derives every
 * share from it. The third test is the control the bridge must never lose: with the key absent
 * everywhere, the Compactor is constructed exactly as it was before the key existed.
 *
 * @internal
 */
final class WindowBudgetConfigTest extends TestCase
{
    private string|false $entorno = false;

    protected function setUp(): void
    {
        $this->entorno = getenv('MILPA_AGENT_CONTEXT_TOKENS');
        putenv('MILPA_AGENT_CONTEXT_TOKENS');
    }

    protected function tearDown(): void
    {
        $this->entorno === false
            ? putenv('MILPA_AGENT_CONTEXT_TOKENS')
            : putenv('MILPA_AGENT_CONTEXT_TOKENS=' . $this->entorno);
    }

    /** The declared key reaches the Compactor as its whole-window budget. */
    public function testTheDeclaredContextReachesTheCompactor(): void
    {
        $compactor = $this->compactorFor(['contextTokens' => 32768]);

        self::assertSame(32768, $this->read($compactor, 'windowBudget'));
    }

    /** With no declaration, the documented environment fallback still works. */
    public function testTheEnvironmentFallbackStillDeclaresTheContext(): void
    {
        putenv('MILPA_AGENT_CONTEXT_TOKENS=24000');

        $compactor = $this->compactorFor(null);

        self::assertSame(24000, $this->read($compactor, 'windowBudget'));
    }

    /**
     * THE CONTROL: absent everywhere, the construction is yesterday's — budget null, defaults whole.
     *
     * If this fails, wiring the budget changed sessions that never asked for one, which is exactly
     * what the null contract of the Compactor forbids.
     */
    public function testAbsentEverywhereTheConstructionIsUnchanged(): void
    {
        $compactor = $this->compactorFor(null);

        self::assertNull($this->read($compactor, 'windowBudget'));
        self::assertSame(40, $this->read($compactor, 'maxTurns'));
        self::assertSame(12, $this->read($compactor, 'keepRecent'));
        self::assertSame(16000, $this->read($compactor, 'maxTokens'));
    }

    /** The key is part of the app's declared contract, so `coa config` can teach it. */
    public function testTheKeyIsDeclaredInTheCatalogue(): void
    {
        $declaradas = AgentKeys::todas();

        self::assertArrayHasKey('agent.contextTokens', $declaradas);
        self::assertSame('int', $declaradas['agent.contextTokens']['type']);
    }

    /** @param array<string, mixed>|null $agente the `agent.*` values this app declares */
    private function compactorFor(?array $agente): object
    {
        $ops = (new \ReflectionClass(AgentOperations::class))->newInstanceWithoutConstructor();

        $contenedor = new class ($agente) implements \Milpa\Interfaces\Di\DIContainerInterface {
            /** @param array<string, mixed>|null $agente */
            public function __construct(private readonly ?array $agente)
            {
            }

            public function getContainer(): \Psr\Container\ContainerInterface
            {
                throw new \LogicException('el doble sólo contesta lo que el puente pregunta');
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
            }

            public function compileContainer(): void
            {
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                return null;
            }

            public function tryGet(string $id): mixed
            {
                return $this->has($id) ? $this->get($id) : null;
            }

            public function has(string $id): bool
            {
                return $id === \Milpa\Runtime\Config::class;
            }

            public function get(string $id): mixed
            {
                // A REAL Config, not a double: the bridge reads dot notation, and a double that
                // answered the whole key would skip the very path being measured.
                return new \Milpa\Runtime\Config(
                    $this->agente === null ? [] : ['agent' => $this->agente],
                );
            }
        };

        $p = (new \ReflectionClass(AgentOperations::class))->getProperty('container');
        $p->setAccessible(true);
        $p->setValue($ops, $contenedor);

        $m = (new \ReflectionClass(AgentOperations::class))->getMethod('compactor');
        $m->setAccessible(true);

        return $m->invoke($ops);
    }

    private function read(object $compactor, string $prop): mixed
    {
        $p = (new \ReflectionClass($compactor))->getProperty($prop);
        $p->setAccessible(true);

        return $p->getValue($compactor);
    }
}
