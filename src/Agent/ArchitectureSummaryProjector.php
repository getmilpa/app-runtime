<?php

/**
 * This file is part of Milpa Framework — the batteries-included host of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Resolver\Report\ResolutionReport;

/**
 * Proyecta el reporte de arquitectura a las pocas líneas que el agente lee ANTES de trabajar.
 *
 * Es un PROYECTOR y no una plantilla, y la diferencia es el defecto que lo hizo nacer: la sección
 * «cómo está armada esta app» del prompt es prosa escrita a mano —el 59 % de lo que el agente lee—
 * que describe la convención mientras el resolver conoce el estado. Nadie tiene una compuerta que
 * las compare, así que envejece sin que se note. Una proyección no puede envejecer: o sale del
 * reporte vivo, o no sale.
 *
 * ## Qué entra, y por qué eso y no más
 *
 * El contenido NO se eligió por intuición: sale de los puntos ciegos que este repositorio midió, cada
 * uno con su acta. Ver `docs/library/pregunta-q-p17e.md`.
 *
 * Y entra poco a propósito. El principio que gobierna el tamaño —candidato, todavía sin promulgar—
 * es que **la información automática debe ser proporcional a la probabilidad de que la tarea la
 * necesite**, no máxima. Por eso el resumen es una línea de conteo cuando no hay nada raro, y crece
 * sólo cuando el grafo tiene algo que decir: una capacidad sin proveedor, un conflicto, una
 * cardinalidad que nadie declaró. El detalle completo sigue a una llamada de distancia
 * (`plugins_architecture`), y el resumen lo dice para que el agente no tenga que enterarse solo.
 *
 * ## Lo que NO entra, y una lección medida
 *
 * La primera versión emitía tres clases de anomalía —sin proveedor, en conflicto, cardinalidad sin
 * declarar— y **dos de las tres eran inalcanzables aquí**. Medido contra la app real: un requisito
 * sin proveedor produce `MILPA_CAPABILITY_MISSING` y **el arranque se bloquea**; dos proveedores
 * exclusivos producen `MILPA_CAPABILITY_CONFLICT` y **también**. Si cualquiera de las dos fuera
 * cierta, no habría agente leyendo este resumen: la app no habría arrancado.
 *
 * O sea que escribí dos líneas que el lector de este texto nunca puede ver — el mismo patrón que
 * este repositorio lleva una semana cazando, en código propio de un día. Se quedan **sólo** porque
 * el mismo proyector sirve a un reporte DIAGNOSTICADO (`plugins.simulate`, `coa doctor`), donde el
 * grafo hipotético sí puede estar roto sin que nada se caiga; y se marcan aquí para que nadie las
 * cuente como presupuesto del prompt de arranque, donde valen cero.
 *
 * Lo único que un agente en marcha puede leer de verdad es el conteo, la cardinalidad sin declarar
 * —que es un AVISO y no bloquea— y dónde está el detalle.
 *
 * ## Lo que tampoco entra
 *
 * Las invariantes del framework —empezando por {@see INVARIANTE}— no se proyectan: no cambian, y
 * medirlas dentro del presupuesto se llevaba un tercio de él en cada corrida. Quedan en la prosa del
 * prompt, marcadas, que es donde el pre-registro pide que viva lo no derivable.
 *
 * ## Presupuesto
 *
 * {@see BUDGET_BYTES} — 115 tokens sobre una línea base de 328, el 35 % que el falsificador 3 de
 * Q-P17-E fijó ANTES de medir. Un resumen que se pase deja de ser la hipótesis que se estaba
 * probando, así que {@see fits()} existe para que una prueba lo verifique y no mi criterio.
 */
final class ArchitectureSummaryProjector
{
    /**
     * El techo del resumen, en bytes.
     *
     * 115 tokens × 4 bytes. La aproximación de 4 bytes por token es la misma que se usó para medir la
     * línea base, así que el cociente es comparable aunque el absoluto no sea exacto — y un
     * presupuesto medido con una regla distinta a la de su línea base no compara nada.
     */
    public const BUDGET_BYTES = 460;

    /**
     * Una afirmación que NO sale del reporte, marcada como tal.
     *
     * `provides.service` promete un cableado que no ocurre: el resolver nunca enlaza, cada plugin lo
     * hace a mano en su `boot()` (ADR-0037). Un agente que lea un manifiesto y crea lo contrario
     * escribe un plugin que declara su servicio y no lo registra — y arranca, y no funciona.
     *
     * NO la emite {@see project()}, y la razón la dio el presupuesto: medida, esta sola línea eran
     * **176 de 482 bytes — el 36 %** de un resumen cuyos 115 tokens son para ESTADO DERIVADO. Una
     * invariante no cambia nunca, así que pagarla en cada proyección es pagar dos veces por lo mismo.
     *
     * Vive aquí como constante para que haya UNA fuente, y la consume la sección de prosa del prompt
     * —donde ya viven las otras invariantes: «andamiar un plugin no lo enciende» y «la persistencia
     * es milpa/data, no Doctrine»—. El pre-registro lo permite explícitamente («se mide el contenido,
     * no el conducto») y así se mantiene la separación que exige: lo derivado en el proyector, lo
     * invariante marcado como tal, nunca mezclados en una lista donde lo segundo se lea como estado.
     */
    /**
     * La línea que nombra la herramienta. Constante para que la condición «sólo puntero» de Q-P17-H
     * use EXACTAMENTE el mismo texto que el resumen completo, y no uno equivalente.
     */
    public const PUNTERO = '- El detalle completo del grafo: llama `plugins_architecture`.';

    public const INVARIANTE = 'Declarar `provides.service` NO cablea nada: el resolver no enlaza, '
        . 'cada plugin registra sus servicios a mano en su `boot()`.';

    /**
     * Las líneas del resumen, o una lista vacía si no hay reporte.
     *
     * Vacía y no un texto de relleno: `AgentOperations::systemPrompt()` une las partes con una línea
     * en blanco, así que una sección vacía deja un hueco donde el modelo espera contenido. Sin
     * reporte no hay arquitectura que proyectar, y decir «no sé» ocupando espacio es peor que callar.
     *
     * @param list<string> $provistas Las capacidades que los plugins DECLARAN proveer. No salen del
     *                                reporte —la resolución la mueven los requisitos— así que quien
     *                                llama las trae de los manifiestos.
     *
     * @return list<string>
     */
    public function project(?ResolutionReport $report, array $provistas = []): array
    {
        if ($report === null) {
            return [];
        }

        $capacidades = $this->soloCapacidades($report->resolved);
        $faltantes = $this->soloCapacidades($report->missing);

        $lineas = [sprintf(
            '- %d capacidad(es) resuelta(s), %d sin proveedor, %d en conflicto.',
            \count($capacidades),
            \count($faltantes),
            \count($report->conflicts),
        )];

        // Las anomalías van NOMBRADAS y no contadas: «1 sin proveedor» obliga a preguntar cuál, que
        // es exactamente la vuelta que este resumen existe para ahorrar.
        //
        // Las dos primeras SÓLO aparecen sobre un reporte diagnosticado: sobre el de arranque son
        // imposibles, porque cualquiera de las dos habría impedido el arranque. Ver el docblock.
        foreach (array_slice($faltantes, 0, 3) as $id) {
            $lineas[] = sprintf('- SIN PROVEEDOR: `%s`.', $id);
        }
        foreach (array_slice($report->conflicts, 0, 2) as $conflicto) {
            $lineas[] = sprintf('- EN CONFLICTO: `%s`.', (string) ($conflicto['id'] ?? '?'));
        }
        foreach ($this->cardinalidadSinDeclarar($report->warnings) as $id) {
            $lineas[] = sprintf('- `%s` tiene varios proveedores y nadie declaró `exclusive`.', $id);
        }

        // Provista y requerida por NADIE. No sale del reporte y por eso hubo que pasarla aparte: la
        // resolución la mueven los REQUISITOS, así que una capacidad que nadie pide no aparece en
        // `resolved` ni en `missing` — es invisible para el reporte entero.
        //
        // Es literalmente el punto ciego que Q-P17-C midió con `MessageChannelInterface`: dos
        // proveedores, cero consumidores, y nada en ninguna superficie que lo dijera. Un resumen que
        // lo omitiera dejaría fuera el único de sus cuatro contenidos que ya tiene un caso real.
        foreach (array_slice($this->sinConsumidor($provistas, $capacidades), 0, 2) as $id) {
            $lineas[] = sprintf('- `%s` se provee y no la requiere nadie.', $id);
        }

        $lineas[] = self::PUNTERO;

        return $lineas;
    }

    /**
     * El resumen como la sección de prompt que {@see project()} produce, o `''` si no hay nada.
     *
     * @param list<string> $provistas
     */
    public function section(?ResolutionReport $report, array $provistas = []): string
    {
        $lineas = $this->project($report, $provistas);
        if ($lineas === []) {
            return '';
        }

        return "Arquitectura de esta app, derivada del resolver:\n" . implode("\n", $lineas);
    }

    /**
     * Sólo el puntero: la línea que nombra la herramienta, sin una sola de estado derivado.
     *
     * Existe para poder MEDIR de dónde viene el efecto. Q-P17-G observó que el resumen colapsa la
     * distancia al instrumento causal a exactamente 1, once de once, y que la primera llamada pasa a
     * ser `plugins_architecture` en 15 de 16 corridas — que es literalmente lo que esta línea pide.
     * Si el puntero solo produce el mismo colapso, el resto del resumen son 68 tokens que no compran
     * nada, y eso hay que saberlo antes de encender nada.
     *
     * Devuelve la MISMA última línea que {@see project()} emite, y no una parecida: dos textos
     * distintos harían que la comparación midiera la redacción en vez del contenido.
     */
    public function pointerOnly(): string
    {
        return self::PUNTERO;
    }

    /**
     * Sólo el estado derivado, SIN la línea que nombra la herramienta.
     *
     * La otra mitad del experimento. Q-P17-H midió que la misma orden se ignora sin datos al lado
     * (`plugins_show` primero, 9 de 16) y se cumple con ellos (`plugins_architecture`, 16 de 16), o
     * sea que lo que dirige parece ser el estado. Esto lo aísla: si el estado solo fija la apertura
     * igual, el puntero sobra — y sobra una línea que además se midió como dañina por su cuenta.
     *
     * Devuelve las MISMAS líneas que {@see section()} menos la última, no un texto parecido: la
     * comparación tiene que ser sobre lo que se quita, no sobre cómo se redactó lo que queda.
     *
     * @param list<string> $provistas
     */
    public function stateOnly(?ResolutionReport $report, array $provistas = []): string
    {
        $lineas = array_values(array_filter(
            $this->project($report, $provistas),
            static fn (string $l): bool => $l !== self::PUNTERO,
        ));

        if ($lineas === []) {
            return '';
        }

        return "Arquitectura de esta app, derivada del resolver:\n" . implode("\n", $lineas);
    }

    /**
     * Si el resumen cabe en el presupuesto que Q-P17-E fijó antes de medir.
     *
     * Existe para que lo compruebe una prueba y no mi criterio: un proyector que crece con el grafo
     * puede pasarse en una app grande sin que nadie lo note, y entonces la medición estaría probando
     * otra hipótesis que la escrita.
     *
     * @param list<string> $provistas
     */
    public function fits(?ResolutionReport $report, array $provistas = []): bool
    {
        return \strlen($this->section($report, $provistas)) <= self::BUDGET_BYTES;
    }

    /**
     * Las capacidades provistas que no resolvieron ningún requisito.
     *
     * @param list<string> $provistas
     * @param list<string> $resueltas
     *
     * @return list<string>
     */
    private function sinConsumidor(array $provistas, array $resueltas): array
    {
        $out = [];
        foreach ($provistas as $id) {
            if (!\in_array($id, $resueltas, true) && !\in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Los ids de las entradas de tipo `capability`, sin las de contrato ni superficie.
     *
     * @param list<array<string, mixed>> $entradas
     *
     * @return list<string>
     */
    private function soloCapacidades(array $entradas): array
    {
        $ids = [];
        foreach ($entradas as $entrada) {
            if (($entrada['kind'] ?? null) !== 'capability') {
                continue;
            }
            $id = $entrada['id'] ?? null;
            if (\is_string($id) && $id !== '' && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Los ids cuya cardinalidad nadie declaró, del aviso que P17.3 introdujo.
     *
     * @param list<array<string, mixed>> $avisos
     *
     * @return list<string>
     */
    private function cardinalidadSinDeclarar(array $avisos): array
    {
        $ids = [];
        foreach ($avisos as $aviso) {
            if (($aviso['code'] ?? null) !== 'MILPA_CAPABILITY_CARDINALITY_UNDECLARED') {
                continue;
            }
            $id = $aviso['id'] ?? null;
            if (\is_string($id) && $id !== '' && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return \array_slice($ids, 0, 2);
    }
}
