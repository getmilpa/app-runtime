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

use Milpa\ToolRuntime\ToolResult;
use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\Agent\PolicyDecision;
use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionPolicy;
use Milpa\Agent\SessionStore;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;

/**
 * Une la política de la sesión con el bucle del agente (P16.4/P16.5).
 *
 * ── POR QUÉ ESTE ADAPTADOR VIVE EN LA APP Y NO EN UN PAQUETE ────────────────────────────────────
 *
 * Porque es lo único que conoce a los dos lados. `milpa/agent` decide —y no sabe qué es un modelo ni
 * una herramienta— y `milpa/ai-gateway` corre el bucle —y no sabe qué es una sesión ni un permiso—.
 * Ponerlo en cualquiera de los dos los ataría, y lo que hoy son dos paquetes que se pueden usar por
 * separado pasarían a ser uno con dos nombres. La app es donde las decisiones concretas se toman.
 *
 * ── NEGAR ES APENDAR ────────────────────────────────────────────────────────────────────────────
 *
 * Cuando la política dice que hay que preguntar, esto NO devuelve nomás una negativa: apenda la
 * pregunta en la sesión. Esa es la diferencia entre un agente que se detiene y uno que se detiene y
 * te espera — la sesión queda no-corrible hasta que alguien conteste, y la pregunta sobrevive al
 * proceso igual que todo lo demás. Una negativa que sólo existiera en el texto de la respuesta se
 * perdería en cuanto cerraras la terminal.
 */
final class SessionToolGate implements ToolCallGate, ToolCallRecorder, ExecutionRecorder
{
    /**
     * @param list<Operation> $operations las de esta app — de ahí salen `mutating` y
     *                                    `requiresConfirmation`, que son declaraciones de la operación
     *                                    y no algo que esta compuerta pueda opinar
     */
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly Session $session,
        private readonly array $operations,
        private readonly SessionPolicy $policy = new SessionPolicy(),
        // Cuánto tiempo tiene un humano para contestar antes de que la sesión se declare muerta.
        // `null` es sin plazo, que es lo que había; la decide el host en `agent.permissionWindow`
        // porque es una política de producto y no una constante de este archivo.
        private readonly ?\DateInterval $permissionWindow = null,
        // LA PETICIÓN, tal cual la escribió el humano. Es contra esto que se verifica el contrato de
        // intención (ADR-0044): sin la petición no hay contra qué comparar un objetivo, y una cadena
        // vacía —lo que había— simplemente desactiva la verificación, que es el comportamiento de
        // toda sesión anterior a que esto existiera.
        private readonly string $petition = '',
        // EL VIGÍA DEL BUCLE ESTÉRIL (Q-P19-R), o `null` para correr como antes. Va aquí y no en su
        // propia compuerta porque esta clase ya es las dos mitades que hacen falta: juzga la llamada
        // ANTES (`refuse`) y ve su resultado DESPUÉS (`recorded`). Una compuerta aparte tendría que
        // reconstruir la segunda mitad, y serían dos verdades sobre lo mismo.
        private readonly ?SterileLoopGuard $vigiaDeBucle = null,
        // LA COMPUERTA DE ORDEN (Q-P20-I), o `null` para correr como antes. Va aquí por lo mismo que
        // el vigía: esta clase ya es las dos mitades —juzga antes, ve el resultado después— y una
        // compuerta aparte tendría que reconstruir la segunda.
        private readonly ?PrerequisiteGate $compuertaPrevia = null,
        // THE ARROW (greenhouse evidence/0009), or `null` to run as before. Unlike the ordering
        // gate it never learns from `recorded()`: it adjudicates durable STATE on every call —
        // executing the rite does not open it; producing the state the rite was meant to
        // demonstrate does.
        private readonly ?TransitionGate $arrow = null,
    ) {
    }

    /**
     * El motivo por el que esta llamada no procede, o `null` si procede.
     *
     * @param array<string, mixed> $arguments
     */
    public function refuse(string $tool, array $arguments): ?string
    {
        // LA EXENCIÓN POR NOMBRE SE RETIRÓ AQUÍ, y lo que la sustituye es la regla.
        //
        // Decía —con razón— que pedir permiso para anotar un plan es pedir permiso para ser legible.
        // Pero lo decía por LISTA DE NOMBRES, y una compuerta que se abre así deja de ser una regla y
        // se vuelve un directorio: el siguiente que necesite lo mismo pide que lo agreguen, y mientras
        // dos mecanismos sostienen el mismo caso nadie sabe cuál lo está sosteniendo.
        //
        // Desde que `SessionBookkeeping` declara su `EffectProfile` (greenhouse evidence/0189), la
        // política decide por el TECHO y las deja pasar sola. Verificado por ejecución antes de
        // quitarla, con el control que separa las dos explicaciones posibles:
        //
        //   clasificadas   + sin exención → el pendiente aterriza
        //   SIN clasificar + sin exención → no aterriza      ← la exención SÍ era lo que las pasaba
        //
        // Sin ese segundo caso esto habría sido quitar algo que ya sobraba y llamarlo arreglo.

        // LA OBLIGACIÓN DE ORDEN VA ANTES QUE TODO LO DEMÁS, y por eso queda debajo de la
        // contabilidad y encima de la operación: lo obligado suele SER contabilidad —«planea antes de
        // empezar»— así que la línea de arriba tiene que dejarlo pasar siempre, o la obligación se
        // volvería imposible de cumplir y la mesa no abriría nunca.
        $falta = $this->compuertaPrevia?->motivoParaEsperar($tool);
        if ($falta !== null) {
            return $falta;
        }

        // THE ARROW COMES AFTER THE OBLIGATION AND BEFORE POLICY: it is a fact with a teaching,
        // not a question — there is nothing a human must decide about a transition durable state
        // has not earned yet. The refusal says what is missing, and with which name.
        $closed = $this->arrow?->reasonToWait($tool);
        if ($closed !== null) {
            return $closed;
        }

        $operacion = $this->operationFor($tool);
        if ($operacion === null) {
            // Una herramienta que no viene de una operación de esta app no la puede juzgar esta
            // política: no sabe si muta. Se deja pasar porque negar lo que no se entiende volvería
            // inútil cualquier registro externo — y porque el registro de herramientas tiene su propia
            // compuerta de scopes, que sigue puesta.
            return null;
        }

        // EL CONTRATO DE INTENCIÓN VA ANTES DE LA POLÍTICA, y ningún modo lo exime (ADR-0044).
        //
        // `auto` exime pedir PERMISO; no exime entender qué se pidió — igual que la firma. Por eso
        // esta verificación no pasa por `SessionPolicy`: la política juzga una intención concreta, y
        // lo que aquí se decide es si ya existe una. El orden también importa para `ask`: preguntar
        // «¿autorizas X sobre Y?» presupone que Y es el objetivo correcto, que es justo lo que está
        // en duda.
        //
        // La verificación es MECÁNICA a propósito — ¿el valor aparece en la petición, sin distinguir
        // mayúsculas? — sin ningún modelo en el circuito: el piso es la autoridad no-persuadible y
        // así se queda. Sus falsos positivos («apaga el plugin de hola» no nombra HelloPlugin)
        // producen una pregunta contestable, no un bloqueo, y su tasa es lo que Q-P19-M mide.
        $duda = $this->intentUnderdetermined($operacion, $arguments);
        if ($duda !== null) {
            return $this->pause($duda);
        }

        // NO REPETIR LO QUE YA FALLÓ DOS VECES IGUAL (Q-P19-R). Va después del contrato de intención
        // y antes de la política: no es una cuestión de autoridad —nadie está pidiendo permiso para
        // nada— sino de no gastar el presupuesto en una llamada cuyo resultado ya se conoce.
        //
        // NO PAUSA LA SESIÓN. Devuelve el motivo sin apendar pregunta: aquí no hay nada que un humano
        // deba decidir, y detener la vuelta por esto sería cobrarle al humano un descuido del modelo.
        // El bucle del agente sigue —`optionRemoved` en la excepción que arma quien nos llama— y el
        // modelo recibe el hecho con el error adentro, que es con lo que puede corregir.
        $bucle = $this->vigiaDeBucle?->motivoParaNoRepetir($tool, $arguments);
        if ($bucle !== null) {
            return $bucle;
        }

        $decision = $this->policy->decide(
            $this->session,
            $operacion->name,
            $operacion->mutating,
            $operacion->requiresConfirmation,
            // El techo se pide AQUÍ, por llamada, y no se guarda en el constructor: si el padre baja
            // a `ask` a media corrida del hijo, la siguiente herramienta ya lo siente. Un techo
            // cacheado se queda viejo exactamente cuando el humano acaba de decidir supervisar —
            // la clase de defecto que Q-P20-B midió (la foto contra el estado vigente).
            $this->sessions->ceilingFor($this->session->id),
        );

        return match ($decision) {
            PolicyDecision::Allow => null,
            // EL «why» SE GUARDA ESTRUCTURADO, igual que el de la pregunta de intención (:254).
            // `SessionPolicy` lo escribe como el JSON pelón de los argumentos, y así el operativo
            // que después quiera saber QUÉ autorizó el humano tendría que sacar la operación del
            // TEXTO de la pregunta. Un consentimiento que sólo se puede reconstruir leyendo prosa no
            // es un hecho: es una redacción (greenhouse decisions/0031).
            PolicyDecision::AskPermission => $this->pause(
                $this->conElHechoAdentro(
                    $this->policy->permissionQuestion($operacion->name, $arguments, $this->vence()),
                    $operacion->name,
                    $arguments,
                ),
            ),
            PolicyDecision::RequireSignature => $this->pause(
                $this->policy->signatureQuestion($operacion->name, $arguments),
            ),
        };
    }

    /**
     * La pregunta que el contrato de intención exige hacer, o `null` si la intención alcanza.
     *
     * Tres salidas en `null`, y las tres son deliberadas: la operación no declara contrato (casi
     * todas), no hay petición contra qué comparar (sesiones viejas), o el argumento declarado no
     * viene en la llamada — eso lo rechaza la validación de schema con su propio error, y duplicar
     * ese juicio aquí sería el segundo comparador que esta casa ya pagó cuatro veces (Q-P17).
     *
     * La pregunta lleva la operación y los argumentos ADENTRO — es la primera aplicación de la
     * restricción que Q-P19-K dejó: toda aprobación necesita tanta evidencia como una negativa, y
     * quien conteste «sí» tiene que poder saber exactamente qué está autorizando.
     *
     * @param array<string, mixed> $arguments
     */
    private function intentUnderdetermined(Operation $operacion, array $arguments): ?\Milpa\Agent\PendingQuestion
    {
        // SE PREGUNTA SI EL CONTRATO EXISTE, no se asume. `namedTarget` nació en milpa/command 0.5 y
        // este `src/` viaja con `composer create-project`: puede convivir con un vendor que su dueño
        // no actualizó —lock viejo, update parcial que no resuelve— y ahí la propiedad no está.
        //
        // Encontrado en la máquina de Rod, y el daño fue desproporcionado: el warning de PHP se
        // escribe sobre la pantalla del TUI y la destruye, así que un desajuste de versiones se veía
        // como un stack trace encima de la conversación. Leer defensivamente cuesta una línea; el
        // pin declara la exigencia y esto la sobrevive cuando el pin no se cumplió todavía.
        $campo = $this->contratoDeclaradoPor($operacion);
        if ($campo === null || $this->petition === '') {
            return null;
        }

        $valor = $arguments[$campo] ?? null;
        if (!\is_string($valor) || trim($valor) === '') {
            return null;
        }

        // A RESUME IS NOT A NEW PETITION (greenhouse decisions/0009). The prompt of a resumed
        // run is literally «sigue», which names nothing by construction — and the series' three
        // non-converters all died on «the petition does not name X» while the human's standing
        // errand named X in lowercase. The target is compared against the STANDING ask: this
        // run's prompt AND the session's goal. Still mechanical, still no model in the circuit;
        // a target named in neither keeps pausing — ADR-0044 lives.
        $standing = $this->petition . "\n" . (string) $this->session->goal;
        if (mb_stripos($standing, trim($valor)) !== false) {
            return null;
        }

        // ── EL CICLO SE CIERRA: Pregunta → Nueva intención ──────────────────────────────────────
        //
        // Si el humano YA confirmó esta operación sobre este objetivo —contestó «sí» a la pregunta
        // que este mismo contrato produjo— el objetivo está nombrado: por el humano, en el stream,
        // con principal. Sin esto, la re-propuesta tras la respuesta volvería a pausar la misma
        // llamada (la petición sigue sin nombrar al objetivo), y una pregunta que contestarla no
        // destraba nada es teatro con acta.
        //
        // Se lee del hecho, no de la prosa: la decisión hereda `reason` y `why` de la pregunta, así
        // que «¿ya se confirmó plugins.disable sobre HelloPlugin?» se contesta comparando código y
        // JSON — nunca el texto de la pregunta, que se redacta y cambia.
        foreach (ContratoInstalado::arreglo($this->session, 'decisions') as $decision) {
            if (($decision['reason'] ?? null) !== 'target_not_named') {
                continue;
            }
            if (! AffirmativeAnswer::is((string) $decision['answer'])) {
                continue;
            }
            $why = json_decode(\is_string($decision['why'] ?? null) ? $decision['why'] : '', true);
            if (!\is_array($why) || ($why['operation'] ?? null) !== $operacion->name) {
                continue;
            }
            $confirmado = \is_array($why['arguments'] ?? null) ? ($why['arguments'][$campo] ?? null) : null;
            if ($confirmado === trim($valor)) {
                return null;
            }
        }

        return new \Milpa\Agent\PendingQuestion(
            id: 'intent-' . substr(sha1($operacion->name . '|' . $valor), 0, 12),
            question: "La petición no nombra a «{$valor}». ¿Confirmas {$operacion->name} sobre «{$valor}»?",
            options: ['sí', 'no'],
            why: json_encode(
                ['operation' => $operacion->name, 'arguments' => $arguments],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) ?: null,
            expiresAt: $this->vence()?->format(\DateTimeInterface::ATOM),
            reason: 'target_not_named',
        );
    }

    /**
     * El argumento que esta operación exige nombrado, leído SIN asumir que el contrato existe.
     *
     * Recibe `object` y no `Operation` a propósito: el análisis estático ve el paquete instalado
     * AQUÍ, donde `namedTarget` siempre existe, y con el tipo estrecho concluiría —con razón, desde
     * su vista— que la comprobación sobra. En runtime no sobra: este `src/` viaja con
     * `composer create-project` y convive con el vendor que su dueño tenga.
     */
    private function contratoDeclaradoPor(object $operacion): ?string
    {
        return ContratoInstalado::cadena($operacion, 'namedTarget');
    }

    /** Cuándo vence la pregunta que se está por hacer, o `null` si el host no puso plazo. */
    private function vence(): ?\DateTimeImmutable
    {
        return $this->permissionWindow === null
            ? null
            : (new \DateTimeImmutable())->add($this->permissionWindow);
    }

    /**
     * Apunta en la sesión que esta herramienta corrió y qué contestó.
     *
     * La compuerta ve la intención y esto ve el desenlace; hacen falta las dos. Sin el desenlace,
     * retomar una sesión sería retomarla sabiendo qué se iba a intentar y no si funcionó — y el agente
     * repetiría el trabajo que su yo anterior ya hizo, o el que ya falló.
     *
     * El resultado se recorta: un `make` que devuelve tres rutas absolutas no aporta más al retomar
     * que su primera línea, y sí ocupa la ventana que la compactación acaba de liberar.
     *
     * @param array<string, mixed> $arguments
     */
    /**
     * Writes down that an operation was materialised — a different question from the one above.
     *
     * {@see self::recorded()} is told about a CALL after it returned, and everything it receives
     * describes the call: what it was, what it got back, whether it failed. This one is told about a
     * FACT, and carries the two identities that fact needs to be auditable a year from now.
     *
     * This class only writes them; it neither observes the executor nor decides the authority. Both
     * arrive already established from the frame that was present when the effect happened, because a
     * gate that filled in an identity here would be filling it in AFTER the act — which is the defect
     * this event exists to remove (greenhouse decisions/0037).
     */
    public function executed(
        string $operation,
        ?Principal $executedBy,
        string $executorSource,
        ?array $authorizedBy,
        string $argumentsDigest,
    ): void {
        $this->sessions->recordExecution(
            $this->session->id,
            $operation,
            $executedBy,
            $executorSource,
            $authorizedBy,
            $argumentsDigest,
        );
    }

    public function recorded(string $tool, array $arguments, string $result, bool $ok): void
    {
        // La contabilidad NO se apunta como llamada. Su efecto ya está en el stream con su propio
        // evento —`plan_set`, `todo_changed`— y el reductor lo devuelve como estado, que es como llega
        // a la ventana. Registrarla además como turno diría lo mismo dos veces y le cobraría a la
        // ventana el doble justo en las sesiones largas, donde el espacio es lo que escasea.
        // LA COMPUERTA DE ORDEN SE ENTERA PRIMERO, antes del corte de abajo. Lo obligado casi siempre
        // es contabilidad, y si aprendiera después del `return` no vería nunca que se cumplió: la mesa
        // quedaría cerrada para siempre por el mismo hecho que venía a abrirla.
        $this->compuertaPrevia?->anota($tool, $ok);

        if ($this->esContabilidad($tool)) {
            return;
        }

        // El vigía ve TODO lo que se ejecutó, incluidas las llamadas de operaciones que esta app no
        // declara: un bucle estéril sobre una herramienta externa gasta el mismo presupuesto.
        $this->vigiaDeBucle?->anota($tool, $arguments, $result, $ok);

        // SI LA LLAMADA MUTABA, lo sabe esta compuerta: tiene la operación delante. El stream no lo
        // guardaba, así que no distinguía mirar de mover — y sin esa distinción no se puede verificar
        // nada sobre las mutaciones, porque como mutaciones son invisibles.
        $operacion = $this->operationFor($tool);

        // EL LOG GUARDA LO QUE LA HERRAMIENTA CONTESTÓ. El recorte vive en `Session::window()`,
        // que es el único consumidor con escasez de espacio.
        //
        // Aquí se cortaba antes, y así un solo `mb_substr` le servía a dos consumidores con
        // necesidades opuestas: la ventana lo quiere corto porque el contexto es lo que se acaba en
        // una sesión larga, y una superficie lo quiere entero para ARMAR la vista del dato. La
        // ventana obtenía lo suyo y la superficie pagaba la cuenta — medido sobre ganado:
        // `capabilities` contestó 2004 caracteres, el log guardó 600, el valor dejó de parsear y el
        // humano no vio tabla ninguna, en la misma sesión donde una llamada más chica sí la tuvo
        // (greenhouse evidence/0203).
        //
        // `resultChars` se sigue mandando: hoy iguala el largo guardado, y ese es el punto. Es el
        // mecanismo que declararía cualquier tope futuro —uno de disco, que es otra escasez y otro
        // tope— y no se quita porque haya dejado de tener algo que confesar (evidence/0202).
        $this->sessions->recordToolCall(
            $this->session->id,
            $tool,
            $arguments,
            $result,
            $ok,
            $operacion instanceof Operation && $operacion->mutating,
            mb_strlen($result),
            // PEDIR NO ES HABER HECHO, y esta compuerta tiene la respuesta delante.
            //
            // Una petición de confirmación y una escritura consumada vuelven las dos con éxito, así
            // que sobre una operación que muta se graban idénticas: `ok` y `mutating`, y las dos
            // cosas son ciertas en ambas. Quien cuente mutaciones desde el stream contaba DOS donde
            // hubo UNA — la cuenta que gobierna el consentimiento (greenhouse evidence/0200).
            //
            // SE LE PREGUNTA AL PROTOCOLO, no se relee aquí. `requires_confirmation` es suyo, y un
            // segundo lector discrepa con el primero el día que cualquiera de los dos cambie
            // (evidence/0141). El predicado además distingue una petición de un PLAN, que lleva la
            // misma llave anidada para decir qué requeriría confirmarse sin estar pidiéndolo.
            ToolResult::asksForConfirmation($result),
        );
    }

    /** Apenda la pregunta —la sesión queda esperando— y devuelve lo que se le dice a quien preguntó. */
    /** Lo que un humano necesita leer de un hecho que trae más de lo que le importa. */
    private static function conQué(?string $why): string
    {
        $hecho = json_decode((string) $why, true);
        if (! \is_array($hecho) || ! \array_key_exists('arguments', $hecho)) {
            return (string) $why;
        }

        return json_encode($hecho['arguments'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            ?: (string) $why;
    }

    /**
     * La misma pregunta, con la operación y los argumentos guardados como dato.
     *
     * @param array<string, mixed> $arguments
     */
    private function conElHechoAdentro(
        \Milpa\Agent\PendingQuestion $pregunta,
        string $operacion,
        array $arguments,
    ): \Milpa\Agent\PendingQuestion {
        return new \Milpa\Agent\PendingQuestion(
            id: $pregunta->id,
            question: $pregunta->question,
            options: $pregunta->options,
            why: json_encode(
                ['operation' => $operacion, 'arguments' => $arguments],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) ?: $pregunta->why,
            expiresAt: $pregunta->expiresAt,
            reason: $pregunta->reason,
        );
    }

    private function pause(\Milpa\Agent\PendingQuestion $pregunta): string
    {
        $this->sessions->ask($this->session->id, $pregunta);

        $linea = $pregunta->question;
        if ($pregunta->why !== null) {
            // LA PROYECCIÓN LEE EL ÁTOMO, no lo escupe. `why` guarda el hecho —operación y
            // argumentos— para que nadie tenga que reconstruirlo del texto; lo que el humano
            // necesita ver es CON QUÉ, no el sobre en el que viaja. Volcar el JSON entero convertía
            // una pregunta legible en un dump, que es la proyección comiéndose al átomo.
            $linea .= "\n  con: " . self::conQué($pregunta->why);
        }
        // AQUÍ NO SE DICE CÓMO CONTESTAR, y antes sí: la línea `contesta con: coa agent:answer …`
        // viajaba dentro del texto de la pausa, así que aparecía TAMBIÉN dentro del TUI — una
        // instrucción de shell en una superficie donde se contesta con Enter, mandando a la gente
        // fuera de la pantalla donde ya le estaban preguntando.
        //
        // La pregunta y sus opciones son del dominio; **cómo se contesta es de cada superficie**. La
        // CLI lo pone en su `hint`, que es el campo que existe para eso; el TUI pinta su widget.
        return $linea;
    }

    /** Si esta herramienta es la contabilidad de la propia sesión — plan y pendientes. */
    private function esContabilidad(string $tool): bool
    {
        foreach (SessionBookkeeping::names() as $nombre) {
            if (McpProjector::toolName($nombre) === $tool) {
                return true;
            }
        }

        return false;
    }

    /**
     * La operación detrás de un nombre de herramienta.
     *
     * La traducción la hace {@see McpProjector::toolName()} y no una regla escrita aquí: si un día
     * cambia cómo se nombran las herramientas, esta compuerta dejaría de encontrar sus operaciones y
     * las dejaría pasar TODAS en silencio. Preguntarle al proyector es lo que impide que una
     * convención duplicada se desincronice sin ruido.
     */
    private function operationFor(string $tool): ?Operation
    {
        foreach ($this->operations as $operacion) {
            if (McpProjector::toolName($operacion->name) === $tool) {
                return $operacion;
            }
        }

        return null;
    }
}
