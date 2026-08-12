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

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\CapabilityIndex;
use Milpa\DevTools\Doctor\Repair;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Descent;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * What this app can do today, and how it grows.
 *
 * ── TWO OPERATIONS, AND THE SECOND ONE IS THE POINT ─────────────────────────────────────────────
 *
 * `capabilities` lists what is installed and what is not. `capabilities:enable` **turns one on**.
 *
 * Handing back the string `composer require milpa/agent milpa/ai-gateway` and stopping there looks
 * safer, and it is not: it leaves the operator to compose a shell command, run it in the right
 * directory, and work out which packages a capability actually needs. That is three decisions the
 * system already knows the answer to — and four rounds of measurement in this programme say every
 * decision handed to the agent costs correctness.
 *
 * **Controlling the environment is how you align the operator.** One operation instead of three steps
 * is not a shortcut: it is the system doing the part it is authoritative about.
 *
 * ── WHY THIS IS NOT A HOLE ──────────────────────────────────────────────────────────────────────
 *
 * An earlier version of this file said the catalogue must never install, because changing an app's
 * dependencies without authorisation belongs to a policy. The premise was right and the conclusion
 * was wrong: what must not happen is installing **without authorisation** — not installing.
 *
 * So this declares `mutating: true` and goes through the same gate every other mutating tool does:
 * the agent proposes, a human consents. And `dry_run` prints the exact command without running it, so
 * consent is given to something legible rather than to a name.
 *
 * ── AND WHY NOT OVER HTTP ───────────────────────────────────────────────────────────────────────
 *
 * Installing a package runs code from the network on the host. That is a different risk class from
 * reading a session, and a scope does not hold it: an HTTP surface reachable from anywhere turns one
 * leaked token into arbitrary code on the box. It stays where whoever invokes it is already on the
 * machine.
 */
final readonly class CapabilityOperations implements CommandProvider
{
    // No constructor, on purpose: `Support\Operations::declared()` builds a provider with the
    // container as soon as it takes one parameter, so a "just for tests" parameter would receive the
    // container in production. The provider contract decides this class's shape.

    /**
     * @return list<Operation>
     */
    /**
     * Las operaciones de capacidades que este grupo aporta al registro.
     *
     * Se devuelven en vez de registrarse solas: quien arma el registro decide qué grupos entran y
     * con qué autoridad, y un grupo que se auto-registrara le quitaría esa decisión.
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'capabilities',
                effects: new EffectProfile(
                    Mutation::None,
                    // Reads `installed.json` from disk. Verified: it does not reach the network,
                    // which is why the catalogue is the one operation a tiny app always has.
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'What this app can do today, and the command that grows it',
                handler: fn (array $input): array => Capabilities::answer(),
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
                // WHAT COMES BACK — and `capabilities:enable` demands a `capability` that nothing said
                // where to get (evidence/0095, 0098). Its declared edge has pointed here since
                // evidence/0098 and came out «unpublished» because this operation published nothing.
                // The chain already ran; it was simply invisible.
                //
                // ── THE THREE COLLECTIONS DO NOT SHARE A SHAPE, AND THAT WAS MEASURED ─────────────
                //
                // `installed` carries an `id` and what each capability provides; `available` carries
                // neither and carries instead the command that installs it. Declaring the second by
                // copying the first would have invented a row — and in THIS house `available` is
                // empty, so its shape could not be observed here at all: it was read from a newborn
                // app under `var/lab/`, where six are available (evidence/0129).
                outputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'ok' => ['type' => 'boolean', 'description' => 'False only when the catalogue itself could not be read'],
                        'source' => ['type' => 'string', 'description' => 'Where the answer came from: the app manifest, the dated index, or the built-in list'],
                        // DECLARED BECAUSE IT IS RETURNED (evidence/0129). The first version of this
                        // schema left it out, and the diff against the cattle's real output exhibited
                        // it: not a lie, since nothing was in excess, but an omission — and an agent
                        // planning against an incomplete schema does not know the key exists.
                        'hint' => ['type' => 'string', 'description' => 'What to do next when there is something worth suggesting; absent when there is not'],
                        'installed' => [
                            'type' => 'array',
                            'description' => 'What this app can do today',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string', 'description' => 'The capability id this house uses in its guards, e.g. «agent-runs»'],
                                    'package' => ['type' => 'string', 'description' => 'The composer package that ships it'],
                                    'title' => ['type' => 'string', 'description' => 'What it is, in one line'],
                                    'unlocks' => ['type' => 'string', 'description' => 'What becomes possible once it is present'],
                                    'provides' => ['type' => 'string', 'description' => 'The surface it contributes'],
                                ],
                                'required' => ['id', 'package', 'title'],
                            ],
                        ],
                        'available' => [
                            'type' => 'array',
                            'description' => 'What this app could do and does not yet — each with the command that grows it',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'package' => ['type' => 'string', 'description' => 'The package name — what capabilities:enable asks for'],
                                    'title' => ['type' => 'string', 'description' => 'What it is, in one line'],
                                    'unlocks' => ['type' => 'string', 'description' => 'What becomes possible once it is installed'],
                                    'command' => ['type' => 'string', 'description' => 'The exact command that installs it'],
                                ],
                                'required' => ['package', 'command'],
                            ],
                        ],
                        'ports' => [
                            'type' => 'array',
                            'description' => 'The seams a capability can plug into, as plain names',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['ok'],
                ],
                mutating: false,
                // EVERY SURFACE. If the agent cannot call it, the system shows the way only to
                // whoever already knew where to look.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'capabilities:refresh',
                effects: new EffectProfile(
                    // Writes the dated index artifact under `var/` — a cache, deletable without loss.
                    Mutation::Persistent,
                    // Reads the package registry. Nothing is sent beyond the request itself.
                    Externality::ThirdParty,
                    Reversibility::Guaranteed,
                    // It changes what this app KNOWS exists — never what it can do.
                    Authority::Read,
                    subject: Subject::Data,
                    rollbackContract: 'delete var/capability-index.json',
                ),
                description: 'Derive the capability index from what the registry publishes, dated',
                handler: fn (array $input): array => $this->refresh(),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: true,
                // NOT over http: a web request that makes this server fan out to a registry and
                // write disk is a surface nobody asked for. The terminal, the TUI and the agent can.
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            new Operation(
                name: 'capabilities:enable',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    // Downloads from a package registry. What arrives is code that will run inside
                    // this app — and by GOV-11 its own declaration about itself is a claim, not a
                    // classification.
                    Externality::ThirdParty,
                    // `composer remove` exists but is not a tested inverse: neither the lock nor the
                    // vendor closure return to where they were.
                    Reversibility::ManualRecovery,
                    // It changes WHAT THIS APP CAN DO. Nothing else in the catalogue does that.
                    Authority::Privileged,
                    subject: Subject::Executable,
                    escalatesOn: ['capability'],
                    // EL ENSAYO NO ES LA COSA (greenhouse decisions/0029).
                    //
                    // Sin esto, `--dry-run` cargaba el techo de la instalación real y S2 le pedía
                    // consentimiento a alguien para NO hacer nada — y de paso el prompt caía donde el
                    // llamador esperaba JSON.
                    //
                    // Las dos mitades están medidas, no argumentadas: evidence/0146 comparó la huella
                    // del disco antes y después —mismos archivos, mismo lock— y evidence/0149 lo corrió
                    // en un espacio de nombres SIN RED con la caché de composer fría: la instalación
                    // real falla ahí y el ensayo pasa. Por eso `Externality` puede bajar también.
                    descents: [new Descent(
                        argument: 'dry_run',
                        whenValue: true,
                        to: new EffectProfile(
                            Mutation::None,
                            Externality::None,
                            Reversibility::Guaranteed,
                            Authority::Read,
                            subject: Subject::None,
                            rollbackContract: 'nothing ran, so there is nothing to undo',
                        ),
                        because: 'the handler returns the composer command it WOULD run without running it: '
                            . 'measured leaving the disk untouched (greenhouse evidence/0146) and succeeding '
                            . 'inside an empty network namespace on a cold cache, where the real install fails '
                            . '(greenhouse evidence/0149)',
                    )],
                ),
                description: 'Install an opt-in capability by name — one step instead of three',
                handler: fn (array $input): array => $this->enable($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'capability' => [
                            'type' => 'string',
                            'description' => 'The package name `capabilities` lists under `available`',
                            // THE PRODUCER CALLS IT `package` AND THIS ASKS FOR `capability` (evidence/0098).
                            // No naming rule was ever going to join those two, and the description
                            // already said so in prose: readable by a human, invisible to the graph.
                            // Declared, the verifier reports it unpublished for as long as
                            // `capabilities` declares no output — debt LOCATED, which is not the same
                            // as debt paid.
                            //
                            // AND IT NAMES THE COLLECTION, not only the key (evidence/0099). The same
                            // response carries `package` under `installed` AND under `available`, with
                            // opposite meanings — what is already here versus what could be added — so
                            // an edge saying only «key package» sent the verifier to the wrong one and
                            // it counted nine where the answer is what the registry offers.
                            'x-milpa-source' => ['tool' => 'capabilities', 'path' => 'available', 'key' => 'package'],
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'Print the exact command instead of running it',
                        ],
                    ],
                    'required' => ['capability'],
                ],
                // MUTATING, AND NO SIGNATURE. It goes through the agent's permission gate like every
                // other mutating tool, but a signature is for what cannot be undone: this is reversed
                // with `composer remove`, and whoever runs it is already on the terminal of this app —
                // the same power. It is the argument `token:new` makes, for the same reason. Asking
                // for a signature here would make it the kind of prompt people approve without
                // reading, which is how the ones that mattered stop being read.
                mutating: true,
                // EL OBJETIVO LO NOMBRA EL HUMANO — y esto lo puso una MEDICIÓN, no una revisión.
                //
                // Q-P20-J (2026-08-04): en el brazo donde la petición NO nombraba el paquete, el
                // agente no llamó `repair` ni una vez —su compuerta sostuvo 0/8— y **instaló igual**,
                // ocho veces, por esta puerta. `capabilities:enable` cambia la app exactamente igual
                // que `repair` y no tenía contrato de intención, así que la restricción existía y no
                // era exhaustiva: se cerró una puerta y quedó la de junto.
                //
                // Una compuerta de autoridad que se puede rodear no es una compuerta; es una
                // sugerencia con mejor prensa. Lo encontró correr el sistema, no leerlo: la prueba
                // unitaria de `repair` seguía en verde mientras esto pasaba.
                namedTarget: 'capability',
                surfaces: ['cli', 'tui', 'mcp'],
            ),
            // B3 (evidence/0091): `repair` is DECLARED here — a hard dependency of every newborn —
            // and IMPLEMENTED in `milpa/devtools`, which is opt-in. `doctor` and `make` do not
            // escape because they live entirely in devtools; only what is split between a hard
            // package and an optional one does. The doctrine is AgentOperations::operations()'s
            // own: what cannot be done is not offered.
            ...(class_exists(Repair::class) ? [new Operation(
                name: 'repair',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    // Runs `composer require` against a registry — verified in `Doctor\Repair`.
                    Externality::ThirdParty,
                    Reversibility::ManualRecovery,
                    Authority::Privileged,
                    subject: Subject::Executable,
                    escalatesOn: ['package'],
                ),
                description: 'Apply one repair the diagnosis recommends, by name — and verify it landed',
                handler: fn (array $input): array => $this->repair($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'package' => [
                            'type' => 'string',
                            'description' => 'The package `coa doctor` recommends installing, exactly as it names it',
                        ],
                        'dry_run' => [
                            'type' => 'boolean',
                            'description' => 'Print the exact command instead of running it',
                        ],
                    ],
                    'required' => ['package'],
                ],
                mutating: true,
                // EL OBJETIVO LO NOMBRA EL HUMANO (ADR-0044). Reparar es la operación con más
                // tentación de decidir sola —el diagnóstico ya «sabe» qué hacer— y por eso es donde
                // más importa que no lo haga: instalar algo que nadie pidió es cambiar la app por una
                // conclusión propia. Si la petición no nombra el paquete, la llamada se detiene y
                // escala.
                namedTarget: 'package',
                surfaces: ['cli', 'tui', 'mcp'],
            )] : []),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function enable(array $input): array
    {
        return Capabilities::install(
            \is_string($input['capability'] ?? null) ? $input['capability'] : '',
            dryRun: ($input['dry_run'] ?? false) === true,
            // The dated index, when one was derived: it widens `available` to what the registry
            // publishes, and it is the PROMISE the delivery gets compared against afterwards.
            index: CapabilityIndex::read(),
        );
    }

    /**
     * Derive the index from the registry and persist it dated — the logic lives with its seam.
     *
     * @return array<string, mixed>
     */
    private function refresh(): array
    {
        return CapabilityIndex::refresh();
    }

    /**
     * Aplicar una reparación que el diagnóstico ya recomendó (P17.6).
     *
     * LA DECISIÓN NO VIVE AQUÍ. Vive en {@see Repair}, que no necesita el kernel — porque el caso que
     * esto atiende es justamente el de una app que no arranca, y una operación sin kernel no corre.
     * Esta es la superficie del agente sobre esa misma decisión; `coa repair` es la otra. Dos
     * implementaciones de «¿procede reparar esto?» discreparían el día que importa.
     *
     * @param array<string, mixed>                                  $input
     * @param null|list<string>                                     $recomendados costura de prueba, la misma que en {@see Repair}
     * @param null|callable(string): array{0: int, 1: list<string>} $corredor     costura de prueba
     *
     * @return array<string, mixed>
     */
    private function repair(array $input, ?array $recomendados = null, ?callable $corredor = null): array
    {
        // MISMA GUARDA QUE EN LA CLI: las dev tools son opt-in, y un `Class not found` es un fatal
        // donde tendría que haber una instrucción.
        if (!class_exists(Repair::class)) {
            return [
                'ok' => false,
                'error' => 'repair vive en las dev tools y esta app no las tiene',
                'hint' => 'composer require milpa/devtools',
            ];
        }

        return Repair::apply(
            // LA RAÍZ ES LA DE LA APP, no la de este paquete. Decía `dirname(__DIR__, 2)` —correcto
            // cuando este archivo vivía dentro de la app— y al mudarse pasó a apuntar al paquete: la
            // reparación comprobaba si el paquete había llegado leyendo el `vendor/` equivocado, y
            // contestaba «composer terminó en 0 y no aparece instalado» sobre algo que sí estaba.
            Capabilities::raizDeLaApp(),
            \is_string($input['package'] ?? null) ? $input['package'] : '',
            ($input['dry_run'] ?? false) === true,
            $recomendados,
            $corredor,
        );
    }
}
