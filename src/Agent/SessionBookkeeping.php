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

use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
/**
 * Las herramientas con las que el agente escribe su propio plan y mueve sus pendientes (P16.3).
 *
 * ── ATADAS A LA SESIÓN, NO PARAMETRIZADAS POR ELLA ──────────────────────────────────────────────
 *
 * El identificador de la sesión se CAPTURA al construirlas; no es un argumento que el modelo pase.
 * Si lo fuera, un modelo confundido podría escribir el plan de otra sesión —o el de una que no es
 * suya— y eso no es una equivocación recuperable: quien lea esa sesión mañana verá un plan que su
 * agente nunca escribió. Un identificador que el modelo no puede nombrar es uno que no puede errar.
 *
 * Por lo mismo sólo existen CUANDO hay sesión: sin ella no habría dónde apendar, y una herramienta
 * que acepta y no guarda es peor que una ausente — el modelo la llama, la ve contestar «ok» y sigue
 * adelante creyendo que dejó un plan.
 *
 * ── POR QUÉ NO PASAN POR LA COMPUERTA DE PERMISOS ───────────────────────────────────────────────
 *
 * Declaran `mutating: true` porque apendan, y mentir sobre eso sería lo peor que puede hacer una
 * declaración. Pero {@see SessionToolGate} las deja pasar sin preguntar, a propósito: pedir permiso
 * para anotar un plan es pedir permiso para ser legible. El efecto de estas herramientas es
 * exclusivamente sobre la bitácora de la propia sesión —no tocan un archivo, una base ni un plugin—
 * y una compuerta que se pide también para eso se aprueba sin leer, que es como se pierde la que sí
 * importaba.
 */
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;

final readonly class SessionBookkeeping implements ContractProducer
{
    public function __construct(
        private SessionStore $sessions,
        private string $sessionId,
    ) {
    }

    /**
     * Los nombres que {@see SessionToolGate} no gatea.
     *
     * Vive aquí, junto a las operaciones, para que agregar una obligue a verla en esta lista. En la
     * compuerta se leería como una lista de excepciones sin dueño.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['plan', 'todo'];
    }

    /**
     * SUMMARY: The contract this notebook declares for `$tool` — the `plan`/`todo` {@see Operation}
     * with its benign self-log `EffectProfile` — or `null` for anything it does not own.
     *
     * This is how {@see SessionToolGate} reaches the profile these operations declare (evidence/0189)
     * without them being in `Operations::all()`: the gate resolves the contract from HERE, the
     * authorized producer, and judges THAT. Their profile is self-legibility (no externality, no
     * authority, subject Data), so the gate lets them pass — by the contract, not by their name.
     */
    public function contractFor(string $tool): ?Operation
    {
        foreach ($this->operations() as $operacion) {
            if (McpProjector::toolName($operacion->name) === $tool) {
                return $operacion;
            }
        }

        return null;
    }

    /**
     * El cuaderno de ESTA sesión como operaciones: `plan` y `todo`, atadas a su id.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'plan',
                description: 'Escribe o reemplaza el plan de trabajo de esta sesión. Hazlo ANTES de empezar algo largo',
                handler: fn (array $input): array => $this->escribirPlan($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'plan' => ['type' => 'string', 'description' => 'El plan, en pasos'],
                    ],
                    'required' => ['plan'],
                ],
                mutating: true,
                // ── EL CUADERNO DICE LO QUE ES, y por eso la compuerta lo deja pasar ────────────
                //
                // Esto llevaba tiempo escrito arriba, en prosa: «pedir permiso para anotar un plan es
                // pedir permiso para ser legible». Nunca fue DATO, y S2 —que exige consentimiento a
                // todo lo que nadie clasificó, porque premiar el silencio volvería opcional cualquier
                // techo (greenhouse decisions/0028)— las alcanzaba. El agente NO PODÍA PLANEAR, que
                // es lo único que la autoridad declaró no negociable.
                //
                // Se clasifica en vez de eximir. `SessionToolGate` ya las exime POR NOMBRE, y una
                // compuerta que se abre por lista de nombres deja de ser una regla y se vuelve un
                // directorio: el siguiente que necesite lo mismo pedirá que lo agreguen. Declarado el
                // perfil, S2 deja de alcanzarlas POR LA REGLA, y cualquier bitácora futura hereda el
                // trato sin que nadie toque nada.
                //
                // `Compensatable` y no `Guaranteed`: un pendiente se mueve o se reemplaza, pero un
                // apéndice no se des-apenda. `Guaranteed` es el único nivel que compra MENOS
                // escrutinio y habría sido la mentira cómoda (greenhouse evidence/0189).
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::None,
                    subject: Subject::Data,
                ),
                // Fuera de HTTP: escriben en la sesión que está corriendo, y no hay una corriendo
                // detrás de una petición web.
                surfaces: ['mcp'],
            ),
            new Operation(
                name: 'todo',
                description: 'Agrega un pendiente o mueve uno que ya existe. Marca `done` en cuanto termines algo',
                handler: fn (array $input): array => $this->pendiente($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string', 'description' => 'What needs doing'],
                        'id' => ['type' => 'string', 'description' => 'El de uno que ya existe, para moverlo'],
                        'replaces' => ['type' => 'string', 'description' => 'The id of the item this one supersedes, when you reword one that already existed'],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['pending', 'in_progress', 'done', 'blocked'],
                            'description' => 'Where it stands; `pending` when unsaid',
                        ],
                    ],
                    'required' => [],
                ],
                mutating: true,
                // ── EL CUADERNO DICE LO QUE ES, y por eso la compuerta lo deja pasar ────────────
                //
                // Esto llevaba tiempo escrito arriba, en prosa: «pedir permiso para anotar un plan es
                // pedir permiso para ser legible». Nunca fue DATO, y S2 —que exige consentimiento a
                // todo lo que nadie clasificó, porque premiar el silencio volvería opcional cualquier
                // techo (greenhouse decisions/0028)— las alcanzaba. El agente NO PODÍA PLANEAR, que
                // es lo único que la autoridad declaró no negociable.
                //
                // Se clasifica en vez de eximir. `SessionToolGate` ya las exime POR NOMBRE, y una
                // compuerta que se abre por lista de nombres deja de ser una regla y se vuelve un
                // directorio: el siguiente que necesite lo mismo pedirá que lo agreguen. Declarado el
                // perfil, S2 deja de alcanzarlas POR LA REGLA, y cualquier bitácora futura hereda el
                // trato sin que nadie toque nada.
                //
                // `Compensatable` y no `Guaranteed`: un pendiente se mueve o se reemplaza, pero un
                // apéndice no se des-apenda. `Guaranteed` es el único nivel que compra MENOS
                // escrutinio y habría sido la mentira cómoda (greenhouse evidence/0189).
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::None,
                    subject: Subject::Data,
                ),
                surfaces: ['mcp'],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function escribirPlan(array $input): array
    {
        $plan = \is_string($input['plan'] ?? null) ? trim($input['plan']) : '';
        if ($plan === '') {
            return ['ok' => false, 'error' => 'falta `plan`: qué vas a hacer'];
        }

        $this->sessions->setPlan($this->sessionId, $plan);

        return ['ok' => true, 'session' => $this->sessionId, 'plan' => $plan];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function pendiente(array $input): array
    {
        $sesion = $this->sessions->load($this->sessionId);
        if ($sesion === null) {
            return ['ok' => false, 'error' => 'esta sesión ya no existe'];
        }

        $id = \is_string($input['id'] ?? null) ? trim($input['id']) : '';
        $texto = \is_string($input['text'] ?? null) ? trim($input['text']) : '';
        $estado = TodoStatus::tryFrom(\is_string($input['status'] ?? null) ? $input['status'] : '');

        if ($id !== '') {
            // Mover uno que existe. El TEXTO se conserva si no se manda otro: un `todo` que sólo venía
            // a marcar `done` no puede borrar la descripción de lo que se hizo, que es lo que después
            // aparece en el resumen al compactar.
            foreach ($sesion->todos as $todo) {
                if ($todo->id === $id) {
                    $actualizado = new Todo($id, $texto !== '' ? $texto : $todo->text, $estado ?? $todo->status);
                    $this->sessions->setTodo($this->sessionId, $actualizado);

                    return ['ok' => true, 'todo' => $actualizado->toArray()];
                }
            }

            return ['ok' => false, 'error' => "no existe el pendiente «{$id}» en esta sesión"];
        }

        if ($texto === '') {
            return ['ok' => false, 'error' => 'falta `text` para uno nuevo, o `id` para mover uno que ya existe'];
        }

        // El id lo pone la app y no el modelo: uno inventado puede chocar con otro y sobrescribir un
        // pendiente ajeno, y ese choque se ve como un pendiente que cambió de texto solo.
        // WHICH ONE THIS SUPERSEDES, and only when that card really exists.
        //
        // VALIDATED rather than believed: a made-up id closing somebody else's card would be the
        // same collision the generated ids above already avoid. And when it does not exist it is
        // said out loud — swallowing it would leave the agent believing it replaced something.
        $supersedes = \is_string($input['replaces'] ?? null) ? trim($input['replaces']) : '';
        $found = false;
        foreach ($sesion->todos as $t) {
            if ($t->id === $supersedes) {
                $found = true;

                break;
            }
        }
        if ($supersedes !== '' && !$found) {
            return ['ok' => false, 'error' => "there is no item «{$supersedes}» for this one to supersede"];
        }

        $nuevo = new Todo(
            't' . (\count($sesion->todos) + 1),
            $texto,
            $estado ?? TodoStatus::Pending,
            replaces: $supersedes !== '' ? $supersedes : null,
        );
        $this->sessions->setTodo($this->sessionId, $nuevo);

        return ['ok' => true, 'todo' => $nuevo->toArray()];
    }
}
