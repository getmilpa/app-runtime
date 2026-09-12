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

namespace Milpa\AppRuntime\Support;

use Milpa\Command\CommandProvider;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Runtime\Kernel;

/**
 * Los átomos de esta app, resueltos UNA vez desde sus dos fuentes: los plugins que arrancaron y lo
 * que `config/operations.php` enlista.
 *
 * Existe porque hay dos superficies que necesitan la misma respuesta —la terminal y HTTP— y cada una
 * resolviéndola por su cuenta es cómo se llega a que `coa` ofrezca una operación que la web no, o al
 * revés. La lista es la misma o las superficies mienten.
 */
final class Operations
{
    /**
     * Lo que esta app DECLARA, sin arrancar nada.
     *
     * ── POR QUÉ EXISTE, SI YA ESTÁ `all()` ──────────────────────────────────────────────────────
     *
     * Porque la tabla de rutas se arma DURANTE el arranque: cuando un plugin contribuye sus rutas, el
     * kernel todavía no existe y las operaciones de los demás todavía no están juntas. Así que el
     * único momento en que se puede saber qué exponer por HTTP es antes, leyendo lo que la app
     * declara.
     *
     * Leer una declaración NO es arrancar: `operations()` de un proveedor devuelve lo que ese
     * proveedor dice que ofrece, resolviendo sus colaboradores del mismo contenedor. Una segunda
     * instancia contesta lo mismo que la primera porque no guarda estado.
     *
     * ── EL LÍMITE, DICHO ────────────────────────────────────────────────────────────────────────
     *
     * Un plugin instalado en tiempo de ejecución —que vive en el store y no en `config/plugins.php`—
     * SÍ aparece en `coa` y en MCP, y NO se puede exponer por HTTP hasta que alguien lo declare. Es
     * una diferencia real entre las superficies, y es preferible a que instalar algo publique rutas
     * que nadie decidió.
     *
     * @param list<class-string> $declared lo que `config/plugins.php` enlista
     *
     * @return list<Operation>
     */
    public static function declared(DIContainerInterface $container, array $declared, string $root): array
    {
        self::authoringBoundary($container, $root);
        $operaciones = [];

        foreach ($declared as $clase) {
            if (!class_exists($clase) || !is_a($clase, CommandProvider::class, true)) {
                continue;
            }
            /** @var CommandProvider $instancia */
            $instancia = new $clase($container);
            foreach ($instancia->operations() as $operacion) {
                $operaciones[] = $operacion;
            }
        }

        foreach (self::providers($root) as $proveedor) {
            $reflexion = new \ReflectionClass($proveedor);
            /** @var CommandProvider $instancia */
            $instancia = ($reflexion->getConstructor()?->getNumberOfParameters() ?? 0) > 0
                ? $reflexion->newInstance($container)
                : $reflexion->newInstance();
            foreach ($instancia->operations() as $operacion) {
                $operaciones[] = $operacion;
            }
        }

        return $operaciones;
    }

    /**
     * @return list<class-string<CommandProvider>>
     */
    private static function providers(string $root): array
    {
        $declarados = $root . '/config/operations.php';
        if (!is_file($declarados)) {
            return [];
        }

        /** @var list<class-string<CommandProvider>> $proveedores */
        $proveedores = require $declarados;

        return array_values(array_filter($proveedores, static fn (string $c): bool => class_exists($c)));
    }

    /**
     * The second pass: hand the finished catalogue to whoever borrows its ceiling from it.
     *
     * A provider is built in order to PRODUCE the catalogue, so when it declares its operations the
     * catalogue does not exist. One that borrows a ceiling therefore borrows from nothing, and
     * GOV-05 makes that the maximum of every axis: safe, and derived from nothing at all. Here the
     * catalogue is complete, so the borrower is asked again with it in hand.
     *
     * WHAT IT IS HANDED EXCLUDES ITS OWN OPERATIONS (evidence/0154). Folding a borrower into its own
     * loan is a fixed point that returns the maximum — deriving nothing while looking exactly like
     * it worked.
     *
     * AND THE LOAN IS A FIXED POINT, NOT A PASS (greenhouse decisions/0224). Two borrowers lend to
     * each other; one sequential pass in `config/operations.php` order hands the first borrower the
     * second's PROVISIONAL maximum, and join() keeps it — measured: `config:set` was unclassified on
     * every axis with `ConfigOperations` listed before `SequenceOperations`, and classified with the
     * two lines swapped. A ceiling that depends on the order of a file is not a ceiling. So the
     * borrowed operations are seeded at their FLOORS and every borrower is rebuilt against the
     * previous round's answers (all of them, none of a neighbour's current round — Jacobi, so no
     * borrower ever sees an order) until nothing moves. Each rebuild is a join over constants and
     * the previous round, hence monotone; the floors are below their own rebuilds; the profile
     * lattice is finite — so the chain rises and stops at the LEAST fixed point, the same one in
     * every order: the closure of what each act can reach. A borrower whose ceiling comes DOWN
     * between rounds (an axis narrower than the round before, or an escalator lost), one that drifts
     * its names, or one that never settles is refused BY NAME rather than tolerated.
     *
     * ONLY THE BORROWERS' OWN OPERATIONS ARE REPLACED. Touching an operation nobody borrowed for is
     * not a loan, it is a rewrite.
     *
     * @param list<Operation>                                    $catalogue
     * @param list<array{0: CatalogueBorrower, 1: list<string>}> $borrowers provider, and the names it contributed
     *
     * @return list<Operation>
     */
    public static function withBorrowedCeilings(array $catalogue, array $borrowers): array
    {
        if ($borrowers === []) {
            return $catalogue;
        }

        // THE SEED: every borrowed operation at its floor, exactly as declared. An axis the floor is
        // silent on is `Unknown` there — the maximum of that axis, absorbing under join (GOV-05) — so
        // it stays unknown through every round, whoever reaches it inherits it, and the published
        // contract says so with `fully_classified: false`. Silence is not a floor, and nothing has to
        // rewrite it to make that true: an Unknown axis is already the top of that axis. (A seed
        // rewritten to the maximum on EVERY axis was tried and measured: the borrower's own rebuild
        // sits below it on the axes it did declare, and the rise check refused it — rightly.)
        /** @var array<string, Operation> $current */
        $current = [];
        foreach ($borrowers as [$borrower, $mine]) {
            $floor = [];
            foreach ($borrower->operationsAtTheFloor() as $op) {
                $floor[$op->name] = $op;
            }
            self::sameNamesOrRefuse($borrower, array_keys($floor), $mine, 'operationsAtTheFloor()');
            $current += $floor;
        }

        // The chain can rise at most `height` steps per axis-set per operation, plus one per escalator
        // it can pick up; a round that moves nothing is the fixed point. Past the bound, somebody is
        // not monotone — and that is a defect to name, not a loop to tolerate.
        $escalators = [];
        foreach ([...$catalogue, ...array_values($current)] as $op) {
            $escalators = [...$escalators, ...$op->effectCeiling()->escalatesOn];
        }
        $bound = 1 + \count($current) * (self::heightOfTheLattice() + \count(array_unique($escalators)));

        for ($round = 1; ; ++$round) {
            if ($round > $bound) {
                throw new \LogicException('the loan did not settle within ' . $bound . ' rounds — a borrower is not monotone in what it is lent');
            }
            $snapshot = $current;
            $next = [];
            foreach ($borrowers as [$borrower, $mine]) {
                $view = [];
                foreach ($catalogue as $op) {
                    if (\in_array($op->name, $mine, true)) {
                        continue;
                    }
                    $view[] = $snapshot[$op->name] ?? $op;
                }
                $rebuilt = $borrower->withCatalogue($view)->operations();
                self::sameNamesOrRefuse($borrower, array_map(static fn (Operation $o): string => $o->name, $rebuilt), $mine, 'withCatalogue()->operations()');
                foreach ($rebuilt as $op) {
                    $was = $snapshot[$op->name]->effectCeiling();
                    $now = $op->effectCeiling();
                    if (!$was->isNoWiderThan($now) || array_diff($was->escalatesOn, $now->escalatesOn) !== []) {
                        throw new \LogicException(\sprintf(
                            '%s: `%s` came DOWN between rounds (%s → %s) — a loan only rises from the floor it starts at (GOV-14); withCatalogue() is not monotone in what it is lent',
                            $borrower::class,
                            $op->name,
                            json_encode(self::canonical($was)),
                            json_encode(self::canonical($now)),
                        ));
                    }
                    $next[$op->name] = $op;
                }
            }

            $moved = false;
            foreach ($next as $name => $op) {
                $moved = $moved || self::canonical($op->effectCeiling()) !== self::canonical($snapshot[$name]->effectCeiling());
            }
            $current = $next;
            if (!$moved) {
                break;
            }
        }

        return array_map(
            static fn (Operation $op): Operation => $current[$op->name] ?? $op,
            $catalogue,
        );
    }

    /**
     * The profile compared as a value: axes by label, escalators as a set, and the rollback contract.
     * `join()` appends escalators in fold order, so the raw list would make two equal ceilings look
     * different.
     *
     * @return array<string, mixed>
     */
    private static function canonical(EffectProfile $profile): array
    {
        $escalators = array_values(array_unique($profile->escalatesOn));
        sort($escalators);

        return [
            'mutation' => $profile->mutation->value,
            'externality' => $profile->externality->value,
            'reversibility' => $profile->reversibility->value,
            'authority' => $profile->authority->value,
            'subject' => $profile->subject->value,
            'escalates_on' => $escalators,
            // The contract too: `join()` keeps its LEFT side's contract when both are Guaranteed, so two
            // such borrowers lending to each other can flip contracts every round while no axis moves.
            // Compared here, that flip is movement, reaches the bound, and is refused — not published
            // as «settled» (found by adversarial review before this shipped).
            'rollback_contract' => $profile->rollbackContract,
        ];
    }

    /** How many strict rises the five axes allow in total, read from the enums rather than written here. */
    private static function heightOfTheLattice(): int
    {
        return Mutation::Unknown->weight()
            + Externality::Unknown->weight()
            + Reversibility::Unknown->weight()
            + Authority::Unknown->weight()
            + Subject::Unknown->weight();
    }

    /**
     * @param list<string> $answered
     * @param list<string> $contributed
     */
    private static function sameNamesOrRefuse(CatalogueBorrower $borrower, array $answered, array $contributed, string $where): void
    {
        $a = $answered;
        $b = $contributed;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new \LogicException(\sprintf(
                '%s: %s names [%s] but operations() contributed [%s] — a borrower answers for the same operations every time, or it is not a borrower',
                $borrower::class,
                $where,
                implode(', ', $answered),
                implode(', ', $contributed),
            ));
        }
    }

    /**
     * Todas las operaciones de esta app, de todos los grupos, en un solo arreglo.
     *
     * Es el único lugar donde se decide qué sabe hacer una app Milpa. Se arma en cada llamada y no
     * se cachea: los plugins activos cambian entre corridas, y una lista guardada enseñaría
     * capacidades de la corrida anterior.
     *
     * @return list<Operation>
     */
    public static function all(Kernel $kernel, string $root): array
    {
        self::authoringBoundary($kernel->container(), $root);
        /** @var list<Operation> $operaciones */
        $operaciones = $kernel->commands();

        $declarados = $root . '/config/operations.php';
        if (!is_file($declarados)) {
            return $operaciones;
        }

        /** @var list<class-string<CommandProvider>> $proveedores */
        $proveedores = require $declarados;
        /** @var list<array{0: CatalogueBorrower, 1: list<string>}> $prestatarios */
        $prestatarios = [];
        foreach ($proveedores as $proveedor) {
            if (!class_exists($proveedor)) {
                continue;
            }
            $reflexion = new \ReflectionClass($proveedor);
            /** @var CommandProvider $instancia */
            $instancia = ($reflexion->getConstructor()?->getNumberOfParameters() ?? 0) > 0
                ? $reflexion->newInstance($kernel->container())
                : $reflexion->newInstance();

            $suyas = [];
            foreach ($instancia->operations() as $operacion) {
                $operaciones[] = $operacion;
                $suyas[] = $operacion->name;
            }

            if ($instancia instanceof CatalogueBorrower) {
                $prestatarios[] = [$instancia, $suyas];
            }
        }

        return self::withBorrowedCeilings($operaciones, $prestatarios);
    }
    /** Install the host's policy once; projection and execution consume the same object. */
    private static function authoringBoundary(DIContainerInterface $container, string $root): void
    {
        \Milpa\AppRuntime\Agent\PluginAuthoringPolicy::install($container, $root);
    }

}
