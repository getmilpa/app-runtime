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
use Milpa\DevTools\Doctor\Repair;
use Milpa\Command\CommandProvider;
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
                description: 'What this app can do today, and the command that grows it',
                handler: fn (array $input): array => Capabilities::answer(),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
                // EVERY SURFACE. If the agent cannot call it, the system shows the way only to
                // whoever already knew where to look.
                surfaces: ['cli', 'tui', 'mcp', 'http'],
            ),
            new Operation(
                name: 'capabilities:enable',
                description: 'Install an opt-in capability by name — one step instead of three',
                handler: fn (array $input): array => $this->enable($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'capability' => [
                            'type' => 'string',
                            'description' => 'The package name `capabilities` lists under `available`',
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
            new Operation(
                name: 'repair',
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
            ),
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
        );
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
