<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\ArchitectureSummaryProjector;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El proyector del resumen de arquitectura (Q-P17-E).
 *
 * Estas pruebas NO miden la hipótesis —eso son 18 corridas con un modelo— sino que el instrumento
 * sea el que se pre-registró: que proyecte estado vivo y no texto fijo, que quepa en el presupuesto
 * que el falsificador 3 fijó antes de medir, y que distinga lo derivado de lo que no lo es.
 */
#[CoversClass(ArchitectureSummaryProjector::class)]
final class ArchitectureSummaryProjectorTest extends TestCase
{
    private ArchitectureSummaryProjector $proyector;

    protected function setUp(): void
    {
        $this->proyector = new ArchitectureSummaryProjector();
    }

    /** @param list<array<string, mixed>> $extra */
    private function reporte(array $resolved = [], array $missing = [], array $conflicts = [], array $warnings = []): ResolutionReport
    {
        return new ResolutionReport(
            status: ResolutionStatus::Valid,
            resolved: $resolved,
            missing: $missing,
            conflicts: $conflicts,
            warnings: $warnings,
        );
    }

    public function testSinReporteNoProyectaNada(): void
    {
        // Vacío y no un relleno: el prompt une sus partes con una línea en blanco, así que una
        // sección vacía deja un hueco donde el modelo espera contenido.
        self::assertSame([], $this->proyector->project(null));
        self::assertSame('', $this->proyector->section(null));
    }

    public function testCuentaLasCapacidadesResueltasYNoLasDeContrato(): void
    {
        $seccion = $this->proyector->section($this->reporte(resolved: [
            ['kind' => 'capability', 'id' => 'acme.uno'],
            ['kind' => 'capability', 'id' => 'acme.dos'],
            ['kind' => 'contract', 'id' => 'Acme\\Contrato'],
            ['kind' => 'surface', 'id' => 'cli'],
        ]));

        self::assertStringContainsString('2 capacidad(es) resuelta(s)', $seccion);
        self::assertStringNotContainsString('Acme\\Contrato', $seccion, 'un contrato no es una capacidad');
    }

    public function testLasAnomaliasVanNombradasYNoContadas(): void
    {
        // Decir «1 sin proveedor» obliga a preguntar cuál — la vuelta exacta que el resumen existe
        // para ahorrar.
        $seccion = $this->proyector->section($this->reporte(
            missing: [['kind' => 'capability', 'id' => 'acme.falta']],
            conflicts: [['id' => 'acme.pelea']],
        ));

        self::assertStringContainsString('SIN PROVEEDOR: `acme.falta`', $seccion);
        self::assertStringContainsString('EN CONFLICTO: `acme.pelea`', $seccion);
    }

    public function testProyectaLaCardinalidadQueNadieDeclaro(): void
    {
        // El aviso que P17.3 introdujo. Sin esto, el punto ciego que Q-P17-C midió —44 provisiones,
        // cero declaraciones— sigue invisible para quien opera el sistema.
        $seccion = $this->proyector->section($this->reporte(warnings: [
            ['code' => 'MILPA_CAPABILITY_CARDINALITY_UNDECLARED', 'id' => 'acme.sink'],
            ['code' => 'MILPA_SUGGESTED_CAPABILITY_MISSING', 'id' => 'acme.otro'],
        ]));

        self::assertStringContainsString('`acme.sink` tiene varios proveedores', $seccion);
        self::assertStringNotContainsString('acme.otro', $seccion, 'otro aviso no es cardinalidad');
    }

    public function testLaInvarianteExisteYNoSeProyecta(): void
    {
        // Medida, esta sola línea era el 36 % del resumen — y una invariante no cambia, así que
        // pagarla en cada proyección es pagar dos veces por lo mismo. Vive como constante, con UNA
        // fuente, y la consume la prosa del prompt donde ya viven las otras invariantes.
        self::assertStringContainsString('provides.service', ArchitectureSummaryProjector::INVARIANTE);
        self::assertStringNotContainsString(
            'provides.service',
            $this->proyector->section($this->reporte()),
            'lo invariante no se proyecta: el presupuesto es para estado derivado',
        );
    }

    public function testDiceDondeEstaElDetalle(): void
    {
        self::assertStringContainsString('plugins_architecture', $this->proyector->section($this->reporte()));
    }

    public function testElResumenEsProporcionalYNoFijo(): void
    {
        // El principio candidato que gobierna el tamaño: proporcional a lo que la tarea puede
        // necesitar, no máximo. Un grafo sano dice una línea; uno con problemas, más.
        $sano = $this->proyector->project($this->reporte(resolved: [['kind' => 'capability', 'id' => 'a']]));
        $roto = $this->proyector->project($this->reporte(
            resolved: [['kind' => 'capability', 'id' => 'a']],
            missing: [['kind' => 'capability', 'id' => 'b']],
            conflicts: [['id' => 'c']],
        ));

        self::assertGreaterThan(\count($sano), \count($roto));
    }

    public function testCabeEnElPresupuestoConUnGrafoSano(): void
    {
        $resolved = [];
        for ($i = 0; $i < 30; ++$i) {
            $resolved[] = ['kind' => 'capability', 'id' => "acme.capacidad.numero.{$i}.v1"];
        }

        self::assertTrue(
            $this->proyector->fits($this->reporte(resolved: $resolved)),
            'un grafo sano de 30 capacidades no puede pasarse: el conteo no crece con el número',
        );
    }

    public function testNoCreceSinLimiteConUnGrafoRoto(): void
    {
        // El riesgo real del presupuesto: una app rota con veinte capacidades sin proveedor no puede
        // convertir el resumen en el reporte entero. Ahí es donde `plugins_architecture` existe.
        $missing = [];
        for ($i = 0; $i < 20; ++$i) {
            $missing[] = ['kind' => 'capability', 'id' => "acme.falta.numero.{$i}.v1"];
        }

        self::assertTrue($this->proyector->fits($this->reporte(missing: $missing)));
    }

    public function testDosReportesDistintosProyectanTextosDistintos(): void
    {
        // El control que separa un PROYECTOR de una plantilla: si la salida no cambia con el estado,
        // no está proyectando nada y el defecto original —prosa que envejece— sigue vivo.
        $a = $this->proyector->section($this->reporte(resolved: [['kind' => 'capability', 'id' => 'x']]));
        $b = $this->proyector->section($this->reporte(
            resolved: [['kind' => 'capability', 'id' => 'x']],
            missing: [['kind' => 'capability', 'id' => 'y']],
        ));

        self::assertNotSame($a, $b);
    }
    /**
     * Medido contra la app real, y es la lección de esta rebanada: un requisito sin proveedor
     * bloquea el arranque (`MILPA_CAPABILITY_MISSING`) y dos proveedores exclusivos también
     * (`MILPA_CAPABILITY_CONFLICT`). Así que sobre el reporte de ARRANQUE las dos son imposibles: si
     * alguna fuera cierta no habría agente leyendo el resumen.
     *
     * Esta prueba fija que el conteo —lo único siempre cierto— aparezca sin ellas, para que nadie
     * las cuente como presupuesto del prompt de arranque, donde valen cero.
     */
    public function testUnGrafoQueArrancaSoloPuedeMostrarElConteoYLosAvisos(): void
    {
        $arrancable = $this->reporte(
            resolved: [['kind' => 'capability', 'id' => 'demo.almacen.v1']],
            warnings: [['code' => 'MILPA_CAPABILITY_CARDINALITY_UNDECLARED', 'id' => 'demo.almacen.v1']],
        );

        $seccion = $this->proyector->section($arrancable);

        self::assertStringContainsString('1 capacidad(es) resuelta(s)', $seccion);
        self::assertStringContainsString('nadie declaró `exclusive`', $seccion, 'un aviso NO bloquea, así que sí se lee');
        self::assertStringNotContainsString('SIN PROVEEDOR', $seccion);
        self::assertStringNotContainsString('EN CONFLICTO', $seccion);
    }
    /**
     * Provista y requerida por NADIE — el punto ciego que Q-P17-C midió con
     * `MessageChannelInterface`: dos proveedores, cero consumidores, y nada en ninguna superficie que
     * lo dijera.
     *
     * Va por parámetro y no del reporte porque el reporte NO LA TIENE: la resolución la mueven los
     * requisitos, así que una capacidad que nadie pide no aparece ni en `resolved` ni en `missing`.
     * Sin esto, el proyector omitía el único de sus cuatro contenidos que ya tiene un caso real —y lo
     * descubrí montando la fixture, no leyendo el código.
     */
    public function testNombraLaCapacidadQueNadieRequiere(): void
    {
        $seccion = $this->proyector->section(
            $this->reporte(resolved: [['kind' => 'capability', 'id' => 'demo.almacen.v1']]),
            ['demo.almacen.v1', 'demo.huerfana.v1'],
        );

        self::assertStringContainsString('`demo.huerfana.v1` se provee y no la requiere nadie', $seccion);
        self::assertStringNotContainsString('`demo.almacen.v1` se provee y no la requiere', $seccion, 'ésa sí se consume');
    }

    public function testSinListaDeProvistasNoInventaHuerfanas(): void
    {
        $seccion = $this->proyector->section($this->reporte(resolved: [['kind' => 'capability', 'id' => 'a']]));

        self::assertStringNotContainsString('no la requiere nadie', $seccion);
    }
    /**
     * El puntero solo, para poder MEDIR de dónde viene el efecto (Q-P17-H).
     *
     * Q-P17-G observó que el resumen colapsa la distancia al instrumento causal a exactamente 1, once
     * de once, y que la primera llamada pasa a ser `plugins_architecture` en 15 de 16 corridas — que
     * es literalmente lo que esta línea pide. Si el puntero solo produce el mismo colapso, el resto
     * del resumen son 68 tokens que no compran nada.
     */
    public function testElPunteroEsElMismoTextoQueCierraElResumen(): void
    {
        // El MISMO, no uno equivalente: dos textos distintos harían que la comparación midiera la
        // redacción en vez del contenido.
        $seccion = $this->proyector->section($this->reporte(resolved: [['kind' => 'capability', 'id' => 'a']]));

        self::assertStringEndsWith($this->proyector->pointerOnly(), $seccion);
    }

    public function testElPunteroNoLlevaNadaDeEstadoDerivado(): void
    {
        $puntero = $this->proyector->pointerOnly();

        self::assertStringContainsString('plugins_architecture', $puntero);
        self::assertStringNotContainsString('capacidad(es) resuelta(s)', $puntero);
        self::assertStringNotContainsString('exclusive', $puntero);
        self::assertStringNotContainsString('no la requiere nadie', $puntero);
    }
    /**
     * El estado SIN el puntero (Q-P17-I): la otra mitad del experimento.
     *
     * Q-P17-H midió que la misma orden se ignora sin datos al lado y se cumple con ellos, o sea que
     * lo que dirige parece ser el estado. Esto lo aísla — y la comparación tiene que ser sobre lo que
     * se QUITA, no sobre cómo se redactó lo que queda, así que son las mismas líneas menos la última.
     */
    public function testElEstadoSoloEsElResumenMenosLaUltimaLinea(): void
    {
        $reporte = $this->reporte(
            resolved: [['kind' => 'capability', 'id' => 'a']],
            warnings: [['code' => 'MILPA_CAPABILITY_CARDINALITY_UNDECLARED', 'id' => 'a']],
        );

        $completo = $this->proyector->section($reporte, ['a', 'b']);
        $estado = $this->proyector->stateOnly($reporte, ['a', 'b']);

        self::assertStringStartsWith($estado, $completo, 'el estado es el prefijo del resumen');
        self::assertSame(
            substr_count($completo, "\n") - 1,
            substr_count($estado, "\n"),
            'exactamente una línea menos',
        );
    }

    public function testElEstadoSoloNUNCANombraLaHerramienta(): void
    {
        // El control que el pre-registro exige, y es exacto: el resumen menciona esa herramienta
        // SÓLO en la línea del puntero, así que su ausencia se puede comprobar por cadena.
        $estado = $this->proyector->stateOnly(
            $this->reporte(resolved: [['kind' => 'capability', 'id' => 'a']]),
            ['a', 'b'],
        );

        self::assertStringNotContainsString('plugins_architecture', $estado);
        self::assertStringContainsString('se provee y no la requiere nadie', $estado, 'pero sí lleva estado');
    }
}
