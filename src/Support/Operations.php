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
        /** @var list<Operation> $operaciones */
        $operaciones = $kernel->commands();

        $declarados = $root . '/config/operations.php';
        if (!is_file($declarados)) {
            return $operaciones;
        }

        /** @var list<class-string<CommandProvider>> $proveedores */
        $proveedores = require $declarados;
        foreach ($proveedores as $proveedor) {
            if (!class_exists($proveedor)) {
                continue;
            }
            $reflexion = new \ReflectionClass($proveedor);
            /** @var CommandProvider $instancia */
            $instancia = ($reflexion->getConstructor()?->getNumberOfParameters() ?? 0) > 0
                ? $reflexion->newInstance($kernel->container())
                : $reflexion->newInstance();

            foreach ($instancia->operations() as $operacion) {
                $operaciones[] = $operacion;
            }
        }

        return $operaciones;
    }
}
