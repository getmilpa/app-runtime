<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\RunInterrupted;
use Milpa\Command\Operation;

/**
 * Delegar una sub-tarea a un sub-agente con contexto fresco (Q-P19-P, spec §5.1/§5.2).
 *
 * ── COMPOSICIÓN, NO CAPACIDAD NUEVA ─────────────────────────────────────────────────────────────
 *
 * El hijo es una sesión con `parentId` — nada más. Su autoridad la ejerce el MISMO juez de siempre:
 * `SessionToolGate` pide el techo del linaje en cada llamada, así que un hijo declarado `auto` bajo
 * un padre en `ask` pausa ante su primera mutación. Este archivo no juzga permisos, no conoce modos
 * y no toca la política: abre la bitácora del hijo, corre su vuelta y trae el reporte. Un segundo
 * sistema de permisos aquí sería el comparador duplicado que esta casa ya pagó cuatro veces (Q-P17).
 *
 * ── EL PADRE RECIBE UN REPORTE, NUNCA EL TRANSCRIPT ─────────────────────────────────────────────
 *
 * Lo que cruza de vuelta es la respuesta final del hijo y su estado — pausado, agotado, fallido —
 * jamás su historial (§5.2). El transcript del hijo vive en el stream del hijo, consultable por id;
 * copiarlo al padre gastaría la ventana del padre en el trabajo que se delegó precisamente para no
 * gastarla.
 *
 * ── EL ID DEL PADRE SE CAPTURA, NO SE LE PIDE AL MODELO ─────────────────────────────────────────
 *
 * Igual que en `SessionBookkeeping`: un id que el modelo pudiera nombrar es uno que puede errar, y
 * colgarle un hijo a otra sesión no es una equivocación recuperable.
 */
final class SubAgentSpawner
{
    /**
     * @param \Closure(string, string, array<int, array{role: string, content: string}>, list<string>): array{answer: string, steps: int} $runChild corre la vuelta del hijo: recibe el encargo, el id de la
     *                                                                                                                                              sesión hija y el historial que el hijo debe ver — vacío al
     *                                                                                                                                              spawnear (§5.1), SU ventana al retomar. El cableado —gate con
     *                                                                                                                                              techo, catálogo sin spawn ni resume— lo pone quien construye
     *                                                                                                                                              esto, porque es quien tiene el kernel y la credencial
     */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly string $parentId,
        private readonly \Closure $runChild,
        // EL FONDO DEL ÁRBOL (§5.4), o `null` para correr sin techo como antes. Vive aquí y no en el
        // cierre porque es de la DELEGACIÓN: quien decide si se puede delegar es quien delega, y la
        // negativa tiene que llegar antes de gastar la vuelta del modelo.
        private readonly ?TreeBudget $presupuesto = null,
    ) {
    }

    /** La herramienta que el padre ve en su catálogo. El hijo no la recibe: profundidad 1 por construcción. */
    public function operation(): Operation
    {
        return new Operation(
            'agent_spawn',
            'Delega una sub-tarea a un sub-agente con contexto fresco. El sub-agente no ve esta '
            . 'conversación: dale en `brief` todo lo que necesita (objetivo, rutas concretas, '
            . 'restricciones) y en `done_when` cómo sabrá que terminó. Devuelve su reporte final, '
            . 'nunca su historial. Útil cuando una sub-tarea es autocontenida y su desarrollo no le '
            . 'importa a esta conversación, sólo su resultado.',
            fn (array $input): array => $this->spawn($input),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'brief' => [
                        'type' => 'string',
                        'description' => 'El encargo completo: objetivo, insumos (rutas, no contenido pegado) y '
                            . 'restricciones. ENUMÉRALO: lo que va numerado llega, y lo que cuelga después de la lista '
                            . 'se pierde — medido, 8/8 contra 1/8.',
                    ],
                    'done_when' => [
                        'type' => 'string',
                        'description' => 'OPCIONAL, y sólo si puedes nombrar un hecho que el sub-agente pueda comprobar '
                            . 'CON LAS HERRAMIENTAS QUE TIENE. Un criterio inalcanzable es peor que ninguno: lo deja '
                            . 'buscando un estado que nunca llega. Ante la duda, omítelo.',
                    ],
                    'must' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Obligaciones que el sub-agente debe cumplir SIEMPRE, una por elemento '
                            . '(p. ej. «escribe un plan antes de empezar»). Van aparte del brief porque llegan '
                            . 'garantizadas: el sistema las numera y las pone al final del encargo. Medido, una '
                            . 'obligación de orden así se cumple 8/8. Para una PROHIBICIÓN prefiere `deny`, que la '
                            . 'ejecuta; para una garantía dura de orden, `first`.',
                    ],
                    'deny' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Herramientas que el sub-agente NO debe tener, por nombre (p. ej. '
                            . '«plugins_lock»). No es una petición: salen de su catálogo y no puede llamarlas. '
                            . 'Prefiere esto a pedirlo en `must` — lo pedido se cumple menos que lo que no existe.',
                    ],
                    'first' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Herramientas que el sub-agente tiene que correr ANTES que cualquier otra, '
                            . 'por nombre (p. ej. «plan»). No es una petición: hasta que corran, el resto de sus '
                            . 'llamadas no proceden. Es la forma ejecutada de «haz X antes de empezar» — pedirlo en '
                            . '`must` entrega la frase, esto cambia lo que puede hacer.',
                    ],
                    'role' => [
                        'type' => 'string',
                        'description' => 'Opcional: el papel del especialista (p. ej. «revisor de seguridad de plugins»).',
                    ],
                ],
                'required' => ['brief'],
            ],
            // NO muta — y no es un tecnicismo: el spawn en sí sólo abre la bitácora del hijo. Cada
            // mutación que el hijo intente la juzga SU compuerta con el techo del linaje, que es
            // donde la pausa puede señalar la llamada concreta. Gatear el spawn juzgaría la
            // categoría («¿autorizas delegar?») en vez de la intención («¿autorizas ESTE make?»),
            // que es exactamente el orden que la política evita.
            mutating: false,
        );
    }

    /**
     * Abre la sesión hija, corre su vuelta y devuelve el reporte.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function spawn(array $input): array
    {
        $brief = \is_string($input['brief'] ?? null) ? trim($input['brief']) : '';
        if ($brief === '') {
            return ['ok' => false, 'error' => 'falta `brief`: el sub-agente no ve esta conversación, así que sin encargo no tiene nada'];
        }

        // EL CRITERIO DE TERMINADO ES OPCIONAL, Y ESO SE MIDIÓ (Q-P19-S).
        //
        // Nació obligatorio: 4 de 8 hijos agotaban su techo trabajando DESPUÉS de cumplir su encargo
        // (Q-P19-R), y exigir el criterio parecía la tesis de la casa aplicada a la utilidad — al
        // ejecutor se le quita adivinar dónde termina, a quien delega se le exige decirlo.
        //
        // La medición lo refutó al revés de lo esperado: con el criterio OBLIGATORIO, el agotamiento
        // subió de 4/8 a 8/8, las llamadas de 7.5 a 14.7 y el cumplimiento CAYÓ de 8/8 a 5/8. Los
        // ocho padres escribieron el mismo criterio razonable —«el plugin aparece en la lista de
        // plugins instalados»— y el hijo se quedó persiguiéndolo.
        //
        // La causa NO es que el criterio fuera inverificable: `plugins:list` y `doctor` lo verían.
        // Es que el estado es INALCANZABLE — `make` andamia en disco y su propia guidance dice
        // «regístralo en config/plugins.php», `plugins:enable` contesta «not installed», y ninguna
        // de las 28 operaciones registra un plugin. El sistema le decía al hijo qué faltaba y no le
        // daba con qué.
        //
        // La lección: **verificable no es lo mismo que alcanzable**, y un criterio de terminado sólo
        // es seguro si nombra un estado que el catálogo del ejecutor sabe producir. Quien redacta el
        // criterio no tiene ese catálogo enfrente; el sistema sí. Mientras esa distinción no exista
        // en la frontera, el criterio queda opcional y el schema advierte lo único que importa.
        $criterio = \is_string($input['done_when'] ?? null) ? trim($input['done_when']) : '';

        $rol = \is_string($input['role'] ?? null) ? trim($input['role']) : '';
        $encargo = $rol === '' ? $brief : "Tu papel: {$rol}.\n\n{$brief}";

        // LAS OBLIGACIONES LLEGAN AUNQUE EL PADRE NO LAS ESCRIBA (Q-P20-E).
        //
        // Medido el 2026-08-03 con n=8 por brazo: una instrucción DENTRO de la enumeración del
        // encargo llega 8/8; la misma frase, las mismas palabras, colgando después de la lista, llega
        // 1/8. El padre reproduce la lista y tira la cola.
        //
        // De ahí sale una regla de escritura que funciona —enumera lo que tenga que llegar— pero eso
        // es DISCIPLINA, no garantía: el día que alguien delegue con prosa suelta, la obligación se
        // pierde y nadie lo nota, porque un brief no tiene quién lo revise. `must` la convierte en
        // propiedad del sistema: el modelo no las redacta, las declara; el spawner las numera y las
        // pone donde la medición dice que sobreviven.
        //
        // Y van AL FINAL y con su nombre. Al final porque es lo último que el hijo lee antes de
        // trabajar; con su nombre porque una obligación mezclada con el encargo se lee como un paso
        // más, y lo que la distingue es que no se negocia con el trabajo.
        $obligaciones = [];
        foreach (\is_array($input['must'] ?? null) ? $input['must'] : [] as $obligacion) {
            if (\is_string($obligacion) && trim($obligacion) !== '') {
                $obligaciones[] = trim($obligacion);
            }
        }

        if ($obligaciones !== []) {
            $numeradas = '';
            foreach ($obligaciones as $i => $obligacion) {
                $numeradas .= "\n" . ($i + 1) . '. ' . $obligacion;
            }
            $encargo .= "\n\nObligaciones de este encargo, que se cumplen siempre:{$numeradas}";
        }

        if ($criterio !== '') {
            // Aparte del trabajo y al final: es la regla de paro, no otro requisito que se mezcle
            // con lo que hay que hacer.
            $encargo .= "\n\nTerminas cuando: {$criterio}\nEn cuanto se cumpla, entrega tu reporte y no sigas.";
        }

        // `auto` DECLARADO A PROPÓSITO: el techo del linaje es quien manda (probado en
        // SessionToolGateTest). Bajo un padre en `auto` el hijo trabaja sin estorbar; bajo uno en
        // `ask` pausa ante su primera mutación. Declararlo `ask` aquí castraría al hijo incluso
        // cuando el humano ya decidió confiar en el árbol completo.
        // UNA PROHIBICIÓN SE EJECUTA, NO SE PIDE.
        //
        // Q-P20-G midió que una obligación entregada al hijo llega 8/8 y gobierna 0/8; Q-P19-F midió
        // que retirar la opción de la mesa redirige 16/16. Es la misma doctrina que esta casa lleva
        // toda la serie encontrando: el sistema hace, el prompt sugiere. `deny` traduce la
        // prohibición de prosa a hecho del entorno.
        //
        // SE LE DICE ADEMÁS, y no es redundante: una herramienta que desaparece sin explicación deja
        // al hijo buscando lo que ya no está: gasta su presupuesto en un catálogo que no coincide con
        // su encargo. El hecho cambia el mundo; la frase le dice por qué cambió.
        $prohibidas = [];
        foreach (\is_array($input['deny'] ?? null) ? $input['deny'] : [] as $herramienta) {
            if (\is_string($herramienta) && trim($herramienta) !== '') {
                $prohibidas[] = trim($herramienta);
            }
        }

        // LA OBLIGACIÓN DE ORDEN, ejecutada (Q-P20-I). `must` entrega la frase —8/8 entregadas, 0/8
        // obedecidas—; esto cierra la mesa: hasta que lo obligado corra, ninguna otra llamada procede.
        // La hipótesis es que «planea antes de empezar» nunca fue una obligación irreducible sino una
        // prohibición disfrazada —«no empieces sin plan»—, y que por eso admite la misma traducción
        // que ya se cumplió 8/8.
        $primero = [];
        foreach (\is_array($input['first'] ?? null) ? $input['first'] : [] as $herramienta) {
            if (\is_string($herramienta) && trim($herramienta) !== '') {
                $primero[] = trim($herramienta);
            }
        }

        // ANTES DE ABRIR LA SESIÓN, no después: un hijo creado y negado deja un stream huérfano que
        // luego alguien tiene que explicar, y no explica nada.
        $sinFondo = $this->presupuesto?->motivoParaNoDelegar();
        if ($sinFondo !== null) {
            return ['ok' => false, 'error' => $sinFondo];
        }

        $hijoId = $this->parentId . '.sub-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->sessions->start($hijoId, $brief, AutonomyMode::Auto, parentId: $this->parentId);

        foreach ($prohibidas as $herramienta) {
            // Con CÓDIGO y no sólo con prosa: quien lea este stream mañana tiene que poder contar
            // por qué se fue una opción sin parsear una frase que para entonces puede no existir.
            $this->sessions->removeOption($hijoId, $herramienta, 'denied-by-delegation', 'quien delegó este trabajo la excluyó del encargo');
        }

        if ($prohibidas !== []) {
            $encargo .= "\n\nEstas herramientas no están en tu catálogo para este encargo: "
                . implode(', ', $prohibidas) . '. No las busques.';
        }

        if ($primero !== []) {
            // SE LE DICE, por lo mismo que a `deny`: el hecho cambia el mundo y la frase le dice por
            // qué. Un hijo que choca con una negativa sin haberla visto venir gasta la llamada que
            // esta línea le ahorra.
            //
            // Y VA AL PRINCIPIO, NO AL FINAL. Apendada empujaba la última etapa del encargo lejos de
            // la cola, y ahí se perdía: 4 de 8 corridas hicieron cuatro de las cinco etapas y la que
            // faltó fue siempre la quinta. Es el mecanismo de posición que Q-P20-E ya había medido
            // —8/8 dentro de la enumeración contra 1/8 colgando después—, y esta frase lo estaba
            // provocando desde el otro lado: no se caía ella, tiraba a la de junto.
            $encargo = 'Antes que cualquier otra cosa corre: ' . implode(', ', $primero)
                . ". Hasta entonces tus demás llamadas no proceden.\n\n" . $encargo;
        }

        // HISTORIAL VACÍO A PROPÓSITO (§5.1): el contexto fresco es la razón de ser del spawn.
        return $this->correr($hijoId, $encargo, [], $primero);
    }

    /**
     * La herramienta para retomar a un hijo contestado (Q-P19-Q). Tampoco la ve el hijo.
     */
    public function resumeOperation(): Operation
    {
        return new Operation(
            'agent_resume',
            'Retoma un sub-agente que quedó pausado y cuya pregunta ya fue contestada. Corre con su '
            . 'propio historial —retomar no es re-delegar— y devuelve su nuevo reporte. Usa el '
            . 'sub_session que agent_spawn te devolvió.',
            fn (array $input): array => $this->resume($input),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'sub_session' => [
                        'type' => 'string',
                        'description' => 'El id de la sesión hija que devolvió agent_spawn.',
                    ],
                ],
                'required' => ['sub_session'],
            ],
            // Misma razón que el spawn: retomar sólo corre la vuelta; cada mutación del hijo la
            // juzga SU compuerta con el techo del linaje, en cada llamada.
            mutating: false,
        );
    }

    /**
     * Retoma la vuelta de un hijo directo, con su propia ventana.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function resume(array $input): array
    {
        $hijoId = \is_string($input['sub_session'] ?? null) ? trim($input['sub_session']) : '';
        if ($hijoId === '') {
            return ['ok' => false, 'error' => 'falta `sub_session`: el id que agent_spawn devolvió'];
        }

        // EL LINAJE SE VALIDA CON LO CAPTURADO, no con lo dicho: `parentId` viene del stream del
        // hijo y el id del padre se capturó al construir. Sin esta línea, cualquier sesión del
        // almacén sería retomable por id — leer y correr el trabajo de otro árbol.
        $hijo = $this->sessions->load($hijoId);
        if ($hijo === null || $hijo->parentId !== $this->parentId) {
            return ['ok' => false, 'error' => "«{$hijoId}» no es un sub-agente de esta sesión"];
        }

        if ($hijo->question !== null) {
            return [
                'ok' => false,
                'error' => "el sub-agente sigue esperando una respuesta: {$hijo->question->question}",
                'sub_session' => $hijoId,
            ];
        }

        if ($hijo->endedBecause !== null) {
            return ['ok' => false, 'error' => "el sub-agente ya terminó: {$hijo->endedBecause}", 'sub_session' => $hijoId];
        }

        // SU VENTANA, no contexto fresco: la decisión contestada ya es un turno de su stream, y
        // retomar con historial vacío sería re-spawnear con otro nombre (falsificador 2 de Q-P19-Q).
        return $this->correr(
            $hijoId,
            'La pregunta que te pausó ya fue contestada — la decisión está en tu historial. Continúa '
            . 'con tu encargo hasta terminar y entrega tu reporte.',
            $hijo->window(),
        );
    }

    /**
     * La vuelta del hijo y su reporte: una sola verdad para spawn y resume.
     *
     * @param array<int, array{role: string, content: string}> $historial
     * @param list<string>                                     $primero   lo que el hijo tiene que
     *                                                                    correr antes que cualquier
     *                                                                    otra cosa
     *
     * @return array<string, mixed>
     */
    private function correr(string $hijoId, string $encargo, array $historial, array $primero = []): array
    {
        $this->sessions->recordTurn($hijoId, 'user', $encargo);

        try {
            $corrida = ($this->runChild)($encargo, $hijoId, $historial, $primero);
        } catch (RunInterrupted $e) {
            // La interrupción del humano NO se traga: para el árbol completo. Convertirla en un
            // reporte de fallo dejaría al padre seguir trabajando después de que el humano dijo alto.
            throw $e;
        } catch (\Throwable $e) {
            // Un hijo que truena produce un reporte explícito, nunca una desaparición (ADR-0029/0033).
            $this->sessions->recordTurn($hijoId, 'assistant', 'La vuelta falló: ' . $e->getMessage());

            return [
                'ok' => false,
                'error' => 'el sub-agente falló: ' . $e->getMessage(),
                'sub_session' => $hijoId,
            ];
        }

        $respuesta = $corrida['answer'];
        $this->sessions->recordTurn($hijoId, 'assistant', $respuesta);

        // SE DESCUENTA LO QUE GASTÓ, no lo que se le autorizó: un hijo que terminó en dos pasos deja
        // los otros diez para sus hermanos. Reservar por adelantado acotaría el árbol al número de
        // delegaciones y no a su costo, que es lo que importa.
        $this->presupuesto?->anota($corrida['steps']);

        $reporte = [
            'ok' => true,
            'report' => $respuesta,
            'sub_session' => $hijoId,
            'steps' => $corrida['steps'],
        ];

        // AGOTARSE SE DICE (§5.4, ADR-0029): un techo alcanzado que se entregara como reporte
        // completo fabricaría la evidencia de que terminó.
        if (class_exists(AgentOrchestrator::class) && $respuesta === AgentOrchestrator::STEPS_EXHAUSTED) {
            $reporte['exhausted'] = true;
            $reporte['report'] = 'El sub-agente se quedó sin pasos antes de terminar.';
        }

        // LA PAUSA DEL HIJO LLEGA AL PADRE CON NOMBRE, no desaparece (falsificador 5 de Q-P19-P).
        // El hijo quedó esperando en SU sesión; contestarle es de la superficie, no de este reporte.
        $hijo = $this->sessions->load($hijoId);
        if ($hijo?->question !== null) {
            $reporte['paused'] = true;
            $reporte['question'] = $hijo->question->question;
        }

        return $reporte;
    }
}
