<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AiGateway\McpClientService;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Consent\OperationId;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * El puente: el sí que el humano ya dio consume el token que esperaba exactamente esa autorización.
 *
 * La batería es de Rod y está congelada en el invernadero (`decisions/0031`), escrita ANTES de que
 * existiera una línea de `ConsentBridge`. Lo que se prueba aquí no es que el puente ejecute — eso
 * sería la mitad fácil— sino las cuatro invariantes que hacen que ejecutar sea legítimo:
 *
 *   Exactitud    · un grant sólo consume un token si cubre la operación Y los argumentos exactos
 *   Unicidad     · un grant no vuelve reusable un token; sigue siendo de un solo uso
 *   Temporalidad · grant vivo + token vencido ≠ ejecución: la autoridad existió, el intento murió
 *   Atribución   · queda la cadena principal → grant → token → llamada
 *
 * Y el control positivo, que es el que decide si esto fue un puente o una excusa: SIN grant, el
 * token NO se consume. Un puente que ejecuta cuando nadie autorizó no es un puente.
 *
 * @internal
 */
final class ConsentBridgeTest extends TestCase
{
    /** @var list<array{tool: string, args: array<string, mixed>}> */
    private array $corridas = [];

    protected function setUp(): void
    {
        if (! class_exists(McpClientService::class)) {
            self::markTestSkipped('sin milpa/ai-gateway no hay cliente que puentear');
        }
        $this->corridas = [];
    }

    /** Un registro con UNA herramienta que muta y exige confirmación — la que acuña el token. */
    private function registro(): ToolRegistry
    {
        $registro = new ToolRegistry(new NullLogger());
        $registro->register(
            'config_set',
            'escribe una llave',
            ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'boolean']]],
            function (array $args): array {
                // `_ctx` lo inyecta el registro; no es un argumento del humano ni de la llamada.
                unset($args['_ctx']);
                $this->corridas[] = ['tool' => 'config_set', 'args' => $args];

                return ['written' => $args];
            },
            new ToolOptions(mutating: true, requiresConfirmation: true),
        );

        return $registro;
    }

    /** @param list<ConsentGrant> $grants */
    private function puente(ToolRegistry $registro, array $grants): ConsentBridge
    {
        return new ConsentBridge($registro, $grants);
    }

    private function grant(string $operacion, array $argumentos): ConsentGrant
    {
        return new ConsentGrant(
            operation: new OperationId($operacion),
            principal: 'cli:rod@casa',
            session: 's1',
            grantedAt: new \DateTimeImmutable('2026-08-13 12:00:00'),
            provenance: 'session.question_answered',
            arguments: $argumentos,
        );
    }

    // ── 1 · el grant cubre exacto → consume el token → EJECUTA ──────────────────────────────────

    public function testAGrantThatCoversExactlyConsumesTheTokenAndTheToolRuns(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        $resultado = $puente->callTool('config_set', ['key' => 'a', 'value' => true]);

        self::assertCount(1, $this->corridas, 'la herramienta corrió exactamente una vez');
        self::assertSame(['key' => 'a', 'value' => true], $this->corridas[0]['args']);
        self::assertIsArray($resultado);
        self::assertArrayNotHasKey('requires_confirmation', $resultado, 'ya no pide un segundo sí');
    }

    // ── 2 · EL CASO ASESINO ─────────────────────────────────────────────────────────────────────

    public function testAGrantForAnotherArgumentValueCannotConsumeThisToken(): void
    {
        $registro = $this->registro();
        // El humano autorizó config_set(a, true). Lo pendiente es config_set(a, FALSE).
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        // Se rechaza UN ESCALÓN ANTES de lo que esta prueba esperaba, y la garantía sale más fuerte:
        // `PolicyGate` compara el mismo grant contra los argumentos de ESTA llamada, así que ni
        // siquiera se acuña un token. No hay nada que consumir porque no llegó a existir.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/needs explicit consent/');

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => false]);
        } finally {
            self::assertSame([], $this->corridas, 'NADA corrió: misma operación, mismo principal, otra llamada');
            self::assertSame([], $puente->consentChain());
        }
    }

    // ── 3 · el inverso ──────────────────────────────────────────────────────────────────────────

    public function testATokenForThisCallIsNotConsumedByAGrantForAnotherOperation(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('plugins_register', ['key' => 'a', 'value' => true])]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/needs explicit consent/');

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => true]);
        } finally {
            self::assertSame([], $this->corridas);
            self::assertSame([], $puente->consentChain());
        }
    }

    // ── 4 · UNICIDAD ────────────────────────────────────────────────────────────────────────────

    public function testAGrantDoesNotMakeATokenReusable(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        $puente->callTool('config_set', ['key' => 'a', 'value' => true]);
        $primerToken = $this->tokenVivo($registro);
        self::assertNull($primerToken, 'el token que se consumió ya no está en el almacén');

        // Un segundo paso legítimo acuña su PROPIO token: el grant no recicla el anterior.
        $puente->callTool('config_set', ['key' => 'a', 'value' => true]);
        self::assertCount(2, $this->corridas, 'dos llamadas, dos tokens, ninguno reusado');
    }

    // ── 5 · TEMPORALIDAD ────────────────────────────────────────────────────────────────────────

    public function testALiveGrantCannotRevivedAnExpiredToken(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        // El TTL del almacén real se pone en negativo ANTES de la llamada, así el token nace
        // vencido. Se hace por reflexión y no con un gancho en la clase de producción: una API que
        // sólo existe para que la prueba pueda empujarla es código de prueba viviendo en producción.
        $this->ttlEnNegativo($registro);

        $resultado = $puente->callTool('config_set', ['key' => 'a', 'value' => true]);

        self::assertSame([], $this->corridas, 'la autoridad existió y el intento ya había muerto');
        self::assertIsArray($resultado);
    }

    // ── 6 · ATRIBUCIÓN ──────────────────────────────────────────────────────────────────────────

    public function testTheChainFromPrincipalToCallIsReconstructible(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, [$this->grant('config_set', ['key' => 'a', 'value' => true])]);

        $puente->callTool('config_set', ['key' => 'a', 'value' => true]);

        $cadena = $puente->consentChain();
        self::assertCount(1, $cadena);
        self::assertSame('cli:rod@casa', $cadena[0]['principal']);
        self::assertSame('config.set', $cadena[0]['operation']);
        self::assertSame('config_set', $cadena[0]['tool']);
        self::assertNotSame('', $cadena[0]['confirm_token'], 'qué token concreto se consumió');
        self::assertSame(['key' => 'a', 'value' => true], $cadena[0]['arguments']);
        self::assertSame('session.question_answered', $cadena[0]['provenance']);
    }

    // ── 7 · EL CONTROL POSITIVO ─────────────────────────────────────────────────────────────────

    public function testWithNoGrantAtAllTheTokenIsNotConsumed(): void
    {
        $registro = $this->registro();
        $puente = $this->puente($registro, []);

        // EL CONTROL: sin ningún grant no hay ejecución, y la razón importa. No es que el puente se
        // porte bien — es que la capa de autoridad no lo deja llegar. Un puente que pudiera ejecutar
        // sin un sí que lo cubra sería el defecto que este diseño existe para quitar, con mejor
        // prensa.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/needs explicit consent/');

        try {
            $puente->callTool('config_set', ['key' => 'a', 'value' => true]);
        } finally {
            self::assertSame([], $this->corridas, 'sin un sí que lo cubra, nada corre');
            self::assertSame([], $puente->consentChain(), 'y no se inventa una cadena de atribución');
        }
    }

    // ── utilidades que tocan el almacén real, porque su TTL no es inyectable ─────────────────────

    private function almacen(ToolRegistry $registro): object
    {
        $p = new \ReflectionProperty(ToolRegistry::class, 'confirmationStore');
        $p->setAccessible(true);

        return $p->getValue($registro);
    }

    private function tokenVivo(ToolRegistry $registro): ?string
    {
        $p = new \ReflectionProperty($this->almacen($registro), 'tokens');
        $p->setAccessible(true);
        $tokens = $p->getValue($this->almacen($registro));

        return $tokens === [] ? null : (string) array_key_first($tokens);
    }

    private function ttlEnNegativo(ToolRegistry $registro): void
    {
        $almacen = $this->almacen($registro);
        $p = new \ReflectionProperty($almacen, 'ttl');
        $p->setAccessible(true);
        $p->setValue($almacen, -1);
    }
}
