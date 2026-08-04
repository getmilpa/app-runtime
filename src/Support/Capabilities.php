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

/**
 * Lo que esta app puede hacer, lo que le falta, y quién lo aporta.
 *
 * ── EL CONTRATO, Y POR QUÉ ES TAN CHICO ─────────────────────────────────────────────────────────
 *
 * Cada paquete que puede entrar a una app Milpa **se anuncia solo**, en su propio `composer.json`:
 *
 * ```json
 * "extra": { "milpa": { "capability": {
 *     "id":       "agent",
 *     "title":    "Sesiones que sobreviven al proceso",
 *     "unlocks":  ["coa chat", "agent:sessions"],
 *     "provides": ["agent.sessions"],
 *     "briefing": "Esta app guarda sesiones de agente: puedes pausarte y pedir permiso."
 * } } }
 * ```
 *
 * Cinco campos y ninguno de más. La primera versión de esto era una **lista escrita a mano dentro del
 * framework**, y ése era exactamente el defecto: un paquete nuevo no podía anunciarse sin que alguien
 * editara el framework, y nadie podía decir «yo también sé guardar sesiones». Un sistema acoplable por
 * naturaleza no puede tener el registro de lo acoplable dentro del acoplador.
 *
 * ── `provides` ES EL CAMPO QUE CONTESTA «¿Y SI QUIERO POSTGRES?» ────────────────────────────────
 *
 * El `id` dice **quién es**; `provides` dice **qué puerto llena**. `milpa/agent` llena
 * `agent.sessions` guardando en archivo; un `milpa/agent-postgres` que declarara el mismo puerto
 * sería una alternativa **nombrable**: `coa capabilities` mostraría los dos y cuál está puesto.
 *
 * Lo que este contrato **no** hace es elegir entre ellos. Elegir es cardinalidad, y ADR-0037 dejó
 * escrito que en este sistema **nadie está decidiendo la cardinalidad de una capacidad** — inventar
 * aquí una regla de desempate sería legislar sobre una pregunta abierta, en el archivo equivocado.
 * Hoy la elección la hace la app registrando el servicio en su contenedor, que es el mecanismo que ya
 * existe y ya funciona. Este contrato la vuelve **visible**; no la resuelve.
 *
 * ── POR QUÉ `installed.json` Y NO UNA SONDA DE CLASE ────────────────────────────────────────────
 *
 * La versión anterior preguntaba `class_exists()` contra una clase elegida como representante de cada
 * capacidad, y tenía dos defectos: la clase la elegía una persona —tres de cinco tenían el namespace
 * equivocado al escribirlas— y `class_exists` contesta `false` para una interfaz, así que una guarda
 * escrita con la función equivocada escondía una capacidad **instalada**.
 *
 * `vendor/composer/installed.json` no tiene ninguno de los dos problemas: es lo que Composer
 * efectivamente puso, lo escribe Composer y no una persona, y responde por paquete y no por un
 * símbolo que alguien nombró de memoria.
 *
 * ── LO QUE NO HACE ──────────────────────────────────────────────────────────────────────────────
 *
 * **No instala.** Devuelve el `composer require` exacto y ahí se detiene. Que un agente pueda
 * describir cómo crecer una app es útil; que pueda cambiarle las dependencias sin que nadie lo
 * autorice pertenece a una política, no a una lista.
 */
final class Capabilities
{
    /**
     * Lo que este piso conoce como posible, aunque no esté instalado.
     *
     * ── POR QUÉ SIGUE HABIENDO UNA LISTA, SI EL CONTRATO ES POR PAQUETE ─────────────────────────
     *
     * Porque un paquete **ausente** no puede anunciarse: su `composer.json` no está en el disco. El
     * contrato resuelve «qué aporta lo que SÍ está»; esta lista resuelve la otra mitad —«qué existe y
     * no está»— que es justo la que hace falta para saber que se puede crecer.
     *
     * Es deliberadamente sólo eso: **nombre y para qué**. Todo lo demás —qué desbloquea, qué puerto
     * llena, qué le dice al agente— lo declara el paquete cuando llega. Una app que instale un
     * paquete Milpa que este piso no conozca lo verá igual en `installed`, con lo que ese paquete diga
     * de sí mismo: esta lista no es una autorización, es una invitación.
     *
     * ── Y POR QUÉ ESTA LISTA TIENE FECHA DE CADUCIDAD ───────────────────────────────────────────
     *
     * Porque «un paquete ausente no puede anunciarse» es cierto **en el disco** y falso **en la red**:
     * Packagist publica el `composer.json` completo de cada versión, `extra.milpa.capability`
     * incluido. Verificado el 2026-08-03 contra `repo.packagist.org/p2/milpa/agent.json`: el
     * `unlocks` que aquí falta, ahí está.
     *
     * Desde ese día los paquetes que se anuncian declaran `"type": "milpa-capability"`, que es lo que
     * los vuelve descubribles **por lo que son y no por cómo se llaman** — antes había que adivinar
     * por el prefijo `milpa/`, lo que dejaba fuera a cualquier tercero, que es justo a quien un
     * sistema acoplable tiene que dejar entrar.
     *
     * Esta lista sobrevive como el piso OFFLINE —un agente que no puede alcanzar la red igual tiene
     * que poder decir qué existe— y ese papel sí es legítimo. Lo que ya no debe hacer es ser la única
     * respuesta: cuando exista el índice derivado, ésta pasa a ser su caché con fecha declarada.
     *
     * @return array<string, string>
     */
    public static function knownOptIns(): array
    {
        return [
            'milpa/agent' => 'Sessions that outlive the process: plan, todos and permissions',
            'milpa/ai-gateway' => 'Let the agent RUN: a model on the other side',
            'milpa/auth' => 'Identity: turns a Bearer into an actor with scopes',
            'milpa/data' => 'Persistence with four backends',
            'milpa/devtools' => 'Scaffolding and diagnosis: make, validate, doctor',
            'milpa/mcp-server' => 'The operations, exposed to an MCP client',
        ];
    }

    /**
     * La raíz de la APP en la que este paquete está instalado — pública porque todo el paquete la necesita.
     *
     * ── POR QUÉ NO SE DEDUCE DE `__DIR__` A SECAS ───────────────────────────────────────────────
     *
     * Porque esta clase vivía DENTRO de la app —era `src/Support/Capabilities.php`— y `dirname(__DIR__,
     * 2)` daba la raíz. Al mudarse a un paquete, esa misma cuenta da la raíz del PAQUETE, y desde ahí
     * `vendor/composer/installed.json` no existe: `capabilities` contestaba que la app no tenía nada
     * instalado, y `coa doctor` respondía «las dev tools no están» con las dev tools puestas.
     *
     * Lo cazó la suite del framework al mudar el archivo. Es la clase de defecto que una mudanza
     * fabrica sin tocar una línea de lógica: **el código no cambió, cambió dónde vive.**
     *
     * Se sube hasta encontrar `vendor/composer/installed.json`, que es el marcador de «aquí vive una
     * app instalada» — funciona igual con el paquete en `vendor/milpa/app-runtime/src/` que con el
     * monorepo por rutas. Si no aparece, se cae al comportamiento de antes en vez de inventar una
     * raíz: una respuesta vacía es honesta, una raíz adivinada no.
     */
    public static function raizDeLaApp(): string
    {
        // SE LE PREGUNTA A COMPOSER, que es quien lo sabe. Subir directorios buscando un `vendor/`
        // parece equivalente y no lo es: en un monorepo por rutas, este paquete tiene su PROPIO
        // `vendor/`, así que la búsqueda se detiene en él y contesta la raíz equivocada — probado.
        //
        // `InstalledVersions` describe el autoloader que está corriendo, o sea la app de verdad.
        if (class_exists(\Composer\InstalledVersions::class)) {
            $raiz = \Composer\InstalledVersions::getRootPackage()['install_path'];
            if (is_dir($raiz)) {
                return rtrim($raiz, '/');
            }
        }

        return \dirname(__DIR__, 2);
    }

    /**
     * Lo que cada paquete instalado declara de sí mismo.
     *
     * @param null|string $vendor la raíz del vendor; se deduce si no se dice
     *
     * @return array<string, array<string, mixed>> por nombre de paquete
     */
    public static function declaredBy(?string $vendor = null): array
    {
        $vendor ??= self::raizDeLaApp() . '/vendor';
        $archivo = $vendor . '/composer/installed.json';

        if (!is_file($archivo)) {
            // Sin `installed.json` no se adivina: un catálogo inventado enseñaría un camino que nadie
            // recorrió. Vacío significa «no lo pude saber».
            return [];
        }

        $json = json_decode((string) file_get_contents($archivo), true);
        $paquetes = \is_array($json) && \is_array($json['packages'] ?? null) ? $json['packages'] : [];

        $declarado = [];
        foreach ($paquetes as $paquete) {
            if (!\is_array($paquete) || !\is_string($paquete['name'] ?? null)) {
                continue;
            }
            $cap = $paquete['extra']['milpa']['capability'] ?? null;
            if (\is_array($cap) && \is_string($cap['id'] ?? null)) {
                $declarado[$paquete['name']] = $cap;
            }
        }

        ksort($declarado);

        return $declarado;
    }

    /** ¿Está puesta esta capacidad, por su `id`? */
    public static function installed(string $id, ?string $vendor = null): bool
    {
        foreach (self::declaredBy($vendor) as $cap) {
            if (($cap['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lo que el agente sabe de esta app **por lo que la app trae puesto**.
     *
     * Es la otra mitad del contrato y la razón por la que `briefing` existe: un paquete no sólo agrega
     * operaciones, agrega **contexto**. Sin esto el agente tendría que deducir de la lista de
     * herramientas qué clase de app opera, y deducir es una decisión más — lo que este programa lleva
     * cuatro tandas midiendo que cuesta.
     *
     * @return list<string>
     */
    public static function briefing(?string $vendor = null): array
    {
        $lineas = [];
        foreach (self::declaredBy($vendor) as $cap) {
            $linea = \is_string($cap['briefing'] ?? null) ? trim($cap['briefing']) : '';
            if ($linea !== '') {
                $lineas[] = $linea;
            }
        }
        sort($lineas);

        return $lineas;
    }

    /**
     * Qué puertos están llenos y por quién.
     *
     * Dos paquetes en el mismo puerto **no es un error aquí**: es exactamente lo que hay que poder
     * ver. Quién gana lo decide la app al registrar el servicio, y ADR-0037 dice por qué este archivo
     * no es el lugar para decidirlo.
     *
     * @return array<string, list<string>> puerto → paquetes que lo llenan
     */
    public static function ports(?string $vendor = null): array
    {
        $puertos = [];
        foreach (self::declaredBy($vendor) as $paquete => $cap) {
            $provee = \is_array($cap['provides'] ?? null) ? $cap['provides'] : [];
            foreach ($provee as $puerto) {
                if (\is_string($puerto)) {
                    $puertos[$puerto][] = $paquete;
                }
            }
        }
        ksort($puertos);

        return $puertos;
    }

    /**
     * El estado completo: lo puesto, lo que falta, y los puertos.
     *
     * @return array{installed: list<array<string, mixed>>, available: list<array<string, mixed>>, ports: array<string, list<string>>}
     */
    public static function state(?string $vendor = null): array
    {
        $declarado = self::declaredBy($vendor);

        $puestas = [];
        foreach ($declarado as $paquete => $cap) {
            $puestas[] = [
                'id' => $cap['id'],
                'package' => $paquete,
                'title' => \is_string($cap['title'] ?? null) ? $cap['title'] : '',
                'unlocks' => \is_array($cap['unlocks'] ?? null) ? array_values($cap['unlocks']) : [],
                'provides' => \is_array($cap['provides'] ?? null) ? array_values($cap['provides']) : [],
            ];
        }

        $faltantes = [];
        foreach (self::knownOptIns() as $paquete => $para) {
            if (isset($declarado[$paquete])) {
                continue;
            }
            $faltantes[] = [
                'package' => $paquete,
                'title' => $para,
                // Lo que desbloquea NO se puede saber hasta instalarlo —lo declara el paquete, y el
                // paquete no está—, así que va vacío y se llena al llegar. Prometer aquí una lista
                // sería el catálogo escrito a mano otra vez.
                'unlocks' => [],
                // EL COMANDO ARMADO, no descrito. Un agente que tiene que componerlo tiene una
                // decisión más que tomar, y ya sabemos lo que cuesta cada una que se le agrega.
                'command' => 'composer require ' . $paquete,
            ];
        }

        return ['installed' => $puestas, 'available' => $faltantes, 'ports' => self::ports($vendor)];
    }

    /**
     * La respuesta completa, armada.
     *
     * Vive aquí y no en la operación por una razón concreta: la operación no puede recibir el vendor
     * —`Support\Operations::declared()` construye un proveedor con el contenedor en cuanto tiene UN
     * parámetro, así que un parámetro «sólo para pruebas» recibiría el contenedor en producción—, y
     * una rama que sólo corre cuando falta algo no se ejercitaría nunca en una app completa. Aquí sí,
     * y el proveedor queda como debe: una declaración de una línea.
     *
     * @return array{ok: bool, installed: list<array<string, mixed>>, available: list<array<string, mixed>>, ports: array<string, list<string>>, hint?: string}
     */
    public static function answer(?string $vendor = null): array
    {
        $estado = self::state($vendor);
        $salida = ['ok' => true, ...$estado];

        $pista = self::hintFor($estado['available']);
        if ($pista !== null) {
            $salida['hint'] = $pista;
        }

        return $salida;
    }

    /**
     * Turn one capability on: resolve it, run its command, and say what it unlocked.
     *
     * ── WHY THE RUNNER IS INJECTABLE ────────────────────────────────────────────────────────────
     *
     * Because the only honest way to test the failing half is to make it fail, and making `composer`
     * fail for real means either no network or a broken tree — neither of which a test should arrange.
     * The seam takes the command and returns `[exit code, output lines]`; production passes nothing
     * and gets `exec`.
     *
     * It is the same seam as `$vendor` in {@see self::declaredBy()}, for the same reason: what a test
     * cannot arrange, it injects — and what it injects is named, not mocked behind a framework.
     *
     * @param null|callable(string): array{0: int, 1: list<string>} $runner
     *
     * @return array<string, mixed>
     */
    public static function install(
        string $pedido,
        ?string $vendor = null,
        ?callable $runner = null,
        bool $dryRun = false,
    ): array {
        $pedido = trim($pedido);
        if ($pedido === '') {
            return ['ok' => false, 'error' => 'missing `capability`: which one'];
        }

        $estado = self::state($vendor);

        // ALREADY THERE IS NOT AN ERROR. Someone who asks twice is told it is done, not that it
        // failed — a failure reads as "this cannot be had" and sends them looking for another way.
        foreach ($estado['installed'] as $puesta) {
            if ($puesta['package'] === $pedido || $puesta['id'] === $pedido) {
                return ['ok' => true, 'capability' => $puesta['package'], 'hint' => 'already installed — nothing to do'];
            }
        }

        $objetivo = null;
        foreach ($estado['available'] as $falta) {
            if ($falta['package'] === $pedido) {
                $objetivo = $falta;
            }
        }

        if ($objetivo === null) {
            return [
                'ok' => false,
                'error' => "unknown capability «{$pedido}»",
                // THE VALID ANSWERS COME WITH THE REFUSAL. Saying only "unknown" makes the caller run
                // a second operation to learn what it should have said.
                'available' => array_map(static fn (array $f): mixed => $f['package'], $estado['available']),
            ];
        }

        $comando = (string) $objetivo['command'];

        // DRY-RUN SALE AQUÍ y no en la operación, para que la línea que se enseña sea LA MISMA que se
        // ejecutaría. Armarla aparte permitiría que el texto dijera una cosa y el código hiciera otra,
        // y quien autoriza estaría consintiendo la versión escrita.
        if ($dryRun) {
            return [
                'ok' => true,
                'capability' => $objetivo['package'],
                'command' => $comando,
                'dry_run' => true,
                'hint' => 'nothing ran — call again without `dry_run` to install',
            ];
        }

        if ($runner === null) {
            $raiz = self::raizDeLaApp();
            $runner = static function (string $cmd) use ($raiz): array {
                $salida = [];
                $codigo = 1;
                exec('cd ' . escapeshellarg($raiz) . ' && ' . $cmd . ' --no-interaction 2>&1', $salida, $codigo);

                return [$codigo, $salida];
            };
        }

        [$codigo, $salida] = $runner($comando);

        if ($codigo !== 0) {
            return [
                'ok' => false,
                'capability' => $objetivo['package'],
                'command' => $comando,
                // THE REAL OUTPUT, not a summary. Composer refuses for reasons only it knows — a
                // version conflict, no network, a locked platform — and hiding them turns a fixable
                // problem into "it did not work".
                'error' => implode("\n", \array_slice($salida, -12)),
            ];
        }

        // UN CERO DE COMPOSER NO ES LA PRUEBA DE QUE LA CAPACIDAD LLEGÓ.
        //
        // El código de salida es una afirmación del subproceso **sobre sí mismo**: dice que composer
        // terminó, no que esta app pueda algo nuevo. Los dos casos se separan porque se dan en la
        // vida real — un `require` que resuelve a una versión sin la declaración, un paquete que la
        // trae mal formada, un «Nothing to install or update» sobre un nombre que no existía.
        //
        // Sin esta comprobación el resultado sería `ok: true` con `unlocked: []`, y un campo que
        // siempre puede venir vacío es la clase de defecto que este repositorio lleva una semana
        // cazando: algo declarado que nunca aterriza. La capacidad se comprueba donde existe —el
        // disco— releyendo lo que el paquete declara de sí mismo.
        $llego = self::unlocksOf((string) $objetivo['package'], $vendor);
        if (!isset(self::declaredBy($vendor)[(string) $objetivo['package']])) {
            return [
                'ok' => false,
                'capability' => $objetivo['package'],
                'command' => $comando,
                'error' => sprintf(
                    'el comando terminó bien pero la capacidad «%s» no apareció: el paquete no está '
                    . 'declarando su capacidad en este vendor.',
                    (string) $objetivo['package'],
                ),
                // La salida real, por lo mismo que en el fallo de arriba: composer sabe por qué.
                'output' => implode("\n", \array_slice($salida, -12)),
                'hint' => 'corre el comando a mano para ver qué resolvió',
            ];
        }

        return [
            'ok' => true,
            'capability' => $objetivo['package'],
            'command' => $comando,
            // WHAT IT UNLOCKED, read AFTER installing — the package could not declare anything
            // before it was on disk, so reading `$objetivo` here would always return an empty list:
            // a field that is always empty is the same defect this repo keeps finding, something
            // declared that never lands.
            'unlocked' => $llego,
            'hint' => 'run `coa list` to see the new operations',
        ];
    }

    /**
     * Lo que un paquete YA INSTALADO dice que desbloquea.
     *
     * @return list<mixed>
     */
    private static function unlocksOf(string $paquete, ?string $vendor): array
    {
        $cap = self::declaredBy($vendor)[$paquete] ?? null;

        return \is_array($cap['unlocks'] ?? null) ? array_values($cap['unlocks']) : [];
    }

    /**
     * La pista, y sólo cuando falta algo.
     *
     * Vive aquí y no dentro de la operación porque así se prueba sobre una lista y no sobre el estado
     * del vendor. Una app completa que igual dijera «puedes instalar» pediría trabajo que no hace
     * falta.
     *
     * @param list<array<string, mixed>> $faltantes
     */
    public static function hintFor(array $faltantes): ?string
    {
        return $faltantes === []
            ? null
            : 'Every `available` entry carries its `command`, ready to run.';
    }
}
