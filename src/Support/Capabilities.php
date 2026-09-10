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

use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Interfaces\Plugin\PluginInterface;

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
     * The governed door, written ONCE so every surface says the same thing.
     *
     * A house that offers `composer require` beside `capabilities:enable` has taught two ways to do
     * one thing and only governed one of them (greenhouse decisions/0241). Callers append the package
     * and the authorisation the surface needs.
     */
    public const ENABLE_COMMAND = 'coa capabilities:enable ';

    /**
     * The capability id each known opt-in declares once installed — so `capabilities:enable identity`
     * resolves to `milpa/auth` BEFORE the package is there to say so itself. Read from each package's own
     * manifest (`extra.milpa.capability.id`) and pinned here; a mismatch on arrival is reported as a
     * promise mismatch, never hidden.
     *
     * THIS LIST AND {@see knownOptIns()} ARE ONE FACT IN TWO PLACES, and they drifted apart the day
     * the floor grew: three capabilities were invited by name and had no id, so the house offered the
     * panel and could not resolve `capabilities:enable admin` for it. A floor entry without an id is
     * an invitation nobody can accept by the name the catalogue prints, which is why the gate in the
     * greenhouse now checks both halves and the id against what the package declares
     * (greenhouse decisions/0247).
     *
     * @return array<string, string> package => id
     */
    public static function knownIds(): array
    {
        return [
            'milpa/admin' => 'admin',
            'milpa/agent' => 'agent',
            'milpa/agent-workspace' => 'agent-workspace',
            'milpa/ai-gateway' => 'agent-runs',
            'milpa/auth' => 'identity',
            'milpa/data' => 'persistence',
            'milpa/devtools' => 'devtools',
            'milpa/mcp-server' => 'mcp',
            'milpa/web-search' => 'web-search',
        ];
    }

    /**
     * The plugins THIS package owns that a capability switches on — declared in `config/plugins.php` by
     * `capabilities:enable` so the door opens without hand edits (greenhouse decisions/0216, point 7).
     *
     * `identity` brings the passkey door: {@see PasskeyPlugin} is app-runtime's own last mile over
     * milpa/auth, so the knowledge that it belongs to that capability lives here, with its owner.
     *
     * @return list<class-string>
     */
    public static function pluginsUnlockedBy(string $id): array
    {
        // THE HOST'S OWN PLUGINS, and this is the whole list on purpose.
        //
        // `identity` is delivered by `milpa/auth`, and the plugin that mounts its ceremony lives HERE,
        // in app-runtime — so no manifest of the delivering package could ever announce it. That is
        // what this arm is for and all it is for. Everything a package brings ITSELF is declared by
        // that package and read from its manifest ({@see pluginsDeclaredBy()}); this used to be the
        // only path, a hand-written list with one arm, which is exactly the acoplador defect ADR-0041
        // names and that this very class exists to end (greenhouse decisions/0241).
        return match ($id) {
            'identity' => [PasskeyPlugin::class],
            default => [],
        };
    }

    /**
     * The plugin classes a capability DECLARES in its own manifest — `extra.milpa.capability.plugins`.
     *
     * The sibling of `operations`, which this class already reads generically: a package that brings a
     * plugin announces it the same way it announces the operations it contributes, and no list here
     * has to grow for a third party to be able to mount a door.
     *
     * Only what the package declared, and only if it is real: a name that does not resolve, or resolves
     * to something that is not a plugin, is REFUSED and named. Installing a capability is not
     * authorising whatever it happens to ship (greenhouse decisions/0240, invariant 1: building grants
     * no authority), so this reads a declaration and verifies it — it never scans for candidates.
     *
     * @param array<string, mixed> $contract the delivered capability contract, from installed.json
     *
     * @return array{plugins: list<string>, refused: array<string, string>}
     */
    public static function pluginsDeclaredBy(array $contract): array
    {
        $plugins = [];
        $refused = [];
        foreach ((array) ($contract['plugins'] ?? []) as $class) {
            if (!\is_string($class) || trim($class, " \\") === '') {
                continue;
            }
            $class = trim($class, " \\");
            if (!class_exists($class)) {
                $refused[$class] = 'the class the manifest declares does not exist in what was installed';

                continue;
            }
            if (!is_a($class, PluginInterface::class, true)) {
                $refused[$class] = 'the class the manifest declares is not a plugin';

                continue;
            }
            $plugins[] = $class;
        }

        return ['plugins' => $plugins, 'refused' => $refused];
    }

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
     * ── LO QUE NO ENTRA, Y POR QUÉ ES PARTE DE LA REGLA ────────────────────────────────────────
     *
     * Un paquete que declara `abandoned` en su `composer.json` se queda fuera aunque se declare
     * capacidad. `milpa/desktop-app` es el caso: dice `abandoned: milpa/agent-workspace`, y ponerlo
     * aquí sería mandar a alguien a un callejón con la autoridad de una invitación de la casa.
     * Contando paquetes que se declaran capacidad, entra; leyendo lo que dicen de sí mismos, no.
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
            // EL PANEL VA PRIMERO porque es la estación 2 del camino de un humano: instala el
            // framework, le pone el panel, y desde el navegador ya tiene interfaz. Faltaba, y la
            // ausencia no rompía nada — quien leía esta lista no tenía forma de saber que la casa
            // tenía un panel (greenhouse decisions/0247).
            'milpa/admin' => 'The admin panel: the house gets a web interface a human can use',
            'milpa/agent' => 'Sessions that outlive the process: plan, todos and permissions',
            'milpa/agent-workspace' => 'The room where a human meets the agent, inside the panel',
            'milpa/ai-gateway' => 'Let the agent RUN: a model on the other side',
            'milpa/auth' => 'Identity: turns a Bearer into an actor with scopes',
            'milpa/data' => 'Persistence with four backends',
            'milpa/devtools' => 'Scaffolding and diagnosis: make, validate, doctor',
            'milpa/mcp-server' => 'The operations, exposed to an MCP client',
            'milpa/web-search' => 'Search the web through a LAN SearXNG, governed as a tool',
        ];
    }

    /**
     * Widens the caret Composer just wrote for a FIRST-PARTY package into the family's own form.
     *
     * `^0.3.1` becomes `>=0.3.1 <1.0` — the same shape `milpa/framework` uses for every dependency it
     * declares, and the shape `audit-stale-pins` audits between siblings. The floor of the range is
     * the version that just installed, so the record says what was actually verified.
     *
     * ── ONLY FIRST PARTY, AND THAT IS THE POINT ─────────────────────────────────────────────────
     *
     * Widening a range is an assertion — «I still work with every minor above this one» — and this
     * house can make it about its own family because it publishes it and audits it. Making it about
     * somebody else's package would be asserting a compatibility nobody verified, which is exactly
     * what the pin gate refuses to automate.
     *
     * The lock is untouched: the installed version satisfies the wider range, so nothing re-resolves.
     *
     * @param string|null $root the app whose manifest to correct; this app's own when not given
     *
     * @return string|null the constraint now recorded, or null when nothing was changed
     */
    private static function widenFirstPartyPin(string $package, ?string $root = null): ?string
    {
        if (!str_starts_with($package, 'milpa/')) {
            return null;
        }

        $path = ($root ?? self::raizDeLaApp()) . '/composer.json';
        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        $manifest = json_decode($raw, true);

        if (!\is_array($manifest) || !\is_array($manifest['require'] ?? null)) {
            return null;
        }

        $current = $manifest['require'][$package] ?? null;

        // ONLY A CARET ON A 0.x, which is the one Composer writes and the only one that is a minor
        // ceiling. A range somebody chose on purpose is left exactly as they chose it.
        if (!\is_string($current) || preg_match('/^\^0\.(\d+(?:\.\d+)*)$/', $current, $m) !== 1) {
            return null;
        }

        $widened = '>=' . substr($current, 1) . ' <1.0';
        $manifest['require'][$package] = $widened;
        $encoded = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($encoded === false || file_put_contents($path, $encoded . "\n") === false) {
            return null;
        }

        return $widened;
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

    /**
     * Declare a third-party capability's operation providers in `config/operations.php`, so its
     * operations project after install. The app DECLARES what it runs (a versioned decision written
     * into config), it does NOT scan the vendor directory — the same law `config/plugins.php` lives
     * under (ADR-0044): what runs in an app is a decision that shows in a diff, not the result of a
     * scan. Idempotent — a provider already declared is left alone.
     *
     * @param list<string> $classes the CommandProvider class-strings the capability names
     *
     * @return list<string> the providers newly written (already-present ones are skipped)
     */
    public static function registerOperations(string $root, array $classes): array
    {
        $file = rtrim($root, '/') . '/config/operations.php';
        if (!is_file($file)) {
            return [];
        }

        $src = (string) file_get_contents($file);
        $escritas = [];
        foreach ($classes as $clase) {
            $clase = trim((string) $clase, " \\");
            if ($clase === '' || str_contains($src, $clase)) {
                continue;
            }
            $pos = strrpos($src, '];');
            if ($pos === false) {
                continue;
            }
            $src = substr($src, 0, $pos) . '    \\' . $clase . "::class,\n" . substr($src, $pos);
            $escritas[] = $clase;
        }

        if ($escritas !== []) {
            file_put_contents($file, $src);
        }

        return $escritas;
    }

    /**
     * Teaches the running autoloader the classes composer just installed under `$vendor`.
     *
     * The loader that booted this process keeps the maps it was born with; a fresh `ClassLoader` over the
     * rewritten `autoload_psr4.php` and `autoload_classmap.php` is prepended so what arrived resolves NOW,
     * not in the next process. Nothing to teach when the maps are absent.
     */
    private static function teachTheRunningLoader(string $vendor): void
    {
        if (! class_exists(\Composer\Autoload\ClassLoader::class)) {
            return;
        }
        $psr4 = is_file($vendor . '/composer/autoload_psr4.php') ? require $vendor . '/composer/autoload_psr4.php' : [];
        $classMap = is_file($vendor . '/composer/autoload_classmap.php') ? require $vendor . '/composer/autoload_classmap.php' : [];
        if (! \is_array($psr4) || ! \is_array($classMap) || ($psr4 === [] && $classMap === [])) {
            return;
        }
        $loader = new \Composer\Autoload\ClassLoader($vendor);
        foreach ($psr4 as $prefix => $paths) {
            $loader->setPsr4((string) $prefix, $paths);
        }
        $loader->addClassMap($classMap);
        $loader->register(true);
    }

    /**
     * Declares plugin classes in `config/plugins.php` — the same insertion `registerOperations()` makes,
     * on the other list. A class already named there is left alone; a file that is not there is not invented.
     *
     * @param list<string> $classes
     *
     * @return list<string> the classes actually written
     */
    public static function registerPlugins(string $root, array $classes): array
    {
        $file = rtrim($root, '/') . '/config/plugins.php';
        if (!is_file($file)) {
            return [];
        }
        $src = (string) file_get_contents($file);
        $written = [];
        foreach ($classes as $class) {
            $class = trim($class, " \\");
            if ($class === '' || str_contains($src, $class)) {
                continue;
            }
            $pos = strrpos($src, '];');
            if ($pos === false) {
                continue;
            }
            $src = substr($src, 0, $pos) . '    \\' . $class . "::class,\n" . substr($src, $pos);
            $written[] = $class;
        }
        if ($written !== []) {
            file_put_contents($file, $src);
        }

        return $written;
    }

    /**
     * Declares `passkey.rpId` in `config/app.php` when nothing declares it yet, and VERIFIES the
     * declaration by loading the file back: a write that did not land is reverted and reported, not
     * assumed. The value is written where the human edits config, with the lines that say who wrote it
     * and why — a declaration on disk, not a default in code.
     *
     * @return array{rpId: string, written: bool, file: string, error?: string}|null `null` when the app has no config/app.php
     */
    public static function declareRelyingParty(string $root, string $rpId = 'localhost'): ?array
    {
        $file = rtrim($root, '/') . '/config/app.php';
        if (!is_file($file)) {
            return null;
        }
        $current = self::loadConfig($file);
        $declared = $current['passkey']['rpId'] ?? null;
        if (\is_string($declared) && $declared !== '') {
            return ['rpId' => $declared, 'written' => false, 'file' => 'config/app.php'];
        }
        $src = (string) file_get_contents($file);
        $pos = strrpos($src, '];');
        if ($pos === false) {
            return ['rpId' => '', 'written' => false, 'file' => 'config/app.php', 'error' => 'config/app.php does not end with the returned array; declare passkey.rpId by hand'];
        }
        $block = "\n    // Declared by `capabilities:enable identity`: the relying-party id passkey assertions bind to.\n"
            . "    // It must equal the host the browser uses (`coa serve` answers at http://localhost:…). Change it\n"
            . "    // to your domain before enrolling anyone there.\n"
            . "    'passkey' => ['rpId' => " . var_export($rpId, true) . "],\n";
        file_put_contents($file, substr($src, 0, $pos) . $block . substr($src, $pos));

        $after = self::loadConfig($file);
        if (($after['passkey']['rpId'] ?? null) !== $rpId) {
            file_put_contents($file, $src);

            return ['rpId' => '', 'written' => false, 'file' => 'config/app.php', 'error' => 'the declaration did not load back from config/app.php, so it was reverted; declare passkey.rpId by hand'];
        }

        return ['rpId' => $rpId, 'written' => true, 'file' => 'config/app.php'];
    }

    /** @return array<string, mixed> */
    private static function loadConfig(string $file): array
    {
        try {
            $loaded = (static fn (): mixed => include $file)();
        } catch (\Throwable) {
            return [];
        }

        return \is_array($loaded) ? $loaded : [];
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
     * ── THE RANK OF AUTHORITIES, EXECUTED ───────────────────────────────────────────────────────
     *
     * Three places answer «what exists», and unranked they diverge on the case nobody tested:
     * `installed.json` (what IS) outranks the derived index (what EXISTS in the registry, DATED)
     * which outranks the offline floor. The answer SAYS which authority it used — a list whose
     * origin cannot be known is a list that cannot be corrected.
     *
     * With an index, `unlocks` and `version` arrive FILLED from what was published — the promise
     * that used to ship empty «until installed». The floor still adds whatever the index does not
     * carry: a smaller answer is honest, a silent one is not.
     *
     * @param null|array<string, mixed> $index the derived artifact; `null` means «there is none»
     *                                         and the floor answers — whoever holds one passes it
     *                                         (the operation passes {@see CapabilityIndex::read()})
     *
     * @return array{installed: list<array<string, mixed>>, available: list<array<string, mixed>>, ports: array<string, list<string>>, source: string, complete: bool, grow?: string}
     */
    public static function state(?string $vendor = null, ?array $index = null): array
    {
        $declarado = self::declaredBy($vendor);

        $puestas = [];
        foreach ($declarado as $package => $cap) {
            $puestas[] = [
                'id' => $cap['id'],
                'package' => $package,
                'title' => \is_string($cap['title'] ?? null) ? $cap['title'] : '',
                'unlocks' => \is_array($cap['unlocks'] ?? null) ? array_values($cap['unlocks']) : [],
                'provides' => \is_array($cap['provides'] ?? null) ? array_values($cap['provides']) : [],
            ];
        }

        $fromIndex = \is_array($index['capabilities'] ?? null) ? $index['capabilities'] : [];

        $faltantes = [];
        foreach ($fromIndex as $package => $cap) {
            if (isset($declarado[$package]) || !\is_array($cap)) {
                continue;
            }
            $faltantes[] = [
                'id' => \is_string($cap['id'] ?? null) ? $cap['id'] : (self::knownIds()[$package] ?? null),
                'package' => $package,
                'title' => \is_string($cap['title'] ?? null) ? $cap['title'] : '',
                // FILLED from what was published: the registry already declared what this version unlocks.
                'unlocks' => \is_array($cap['unlocks'] ?? null) ? array_values($cap['unlocks']) : [],
                'version' => \is_string($cap['version'] ?? null) ? $cap['version'] : '',
                // THE GOVERNED DOOR, and the same one everywhere (greenhouse decisions/0241).
                //
                // This used to read `composer require <package>` — the door that walks past the gate,
                // the consent and the effect profile, offered by the house's own catalogue as if it
                // were the way. `capabilities:enable` is the way; `composer require` still works for
                // whoever types it, but the house stops TEACHING it. And `--sign` is part of the
                // command because without it the call is refused, so a command printed without it is
                // a command that does not run.
                'command' => self::ENABLE_COMMAND . $package . ' --sign',
            ];
        }

        foreach (self::knownOptIns() as $paquete => $para) {
            if (isset($declarado[$paquete]) || isset($fromIndex[$paquete])) {
                continue;
            }
            $faltantes[] = [
                'id' => self::knownIds()[$paquete] ?? null,
                'package' => $paquete,
                'title' => $para,
                // What it unlocks CANNOT be known with no network and no package: it ships empty
                // and fills on arrival. Promising a list here would be the hand-written catalogue again.
                'unlocks' => [],
                // EL COMANDO ARMADO, no descrito. Un agente que tiene que componerlo tiene una
                // decisión más que tomar, y ya sabemos lo que cuesta cada una que se le agrega.
                'command' => self::ENABLE_COMMAND . $paquete . ' --sign',
            ];
        }

        $date = \is_string($index['derived_at'] ?? null) ? $index['derived_at'] : null;

        return [
            'installed' => $puestas,
            'available' => $faltantes,
            'ports' => self::ports($vendor),
            'source' => $date !== null
                ? "registry index derived {$date}, offline floor beneath"
                : 'offline floor — no derived index; run `capabilities:refresh` to build one',
            // AND SAID AS A FACT, not only inside a sentence a surface may or may not print. A house
            // that has never derived its index is showing a FLOOR — a handful of packages this
            // runtime happens to know by name — and presenting it as the world is how a human
            // concludes the panel does not exist (greenhouse decisions/0241).
            'complete' => $date !== null,
            ...($date === null ? ['grow' => 'coa capabilities:refresh'] : []),
        ];
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
     * 🚨 IT DECLARED FIVE KEYS AND RETURNED SEVEN. `source` — where the offer list was read from,
     * with its date and whether an offline floor is showing through — and `complete` were both
     * absent from this annotation, so a consumer that trusted it would never read the provenance
     * this method goes to the trouble of computing, and one that read it anyway had to guard
     * against a key static analysis swore did not exist. Measured by execution against a booted
     * app (greenhouse decisions/0266).
     *
     * @return array{ok: bool, installed: list<array<string, mixed>>, available: list<array<string, mixed>>, ports: array<string, list<string>>, source: string, complete: bool, hint?: string}
     */
    public static function answer(?string $vendor = null): array
    {
        $estado = self::state($vendor, CapabilityIndex::read());
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
     * @param null|array<string, mixed>                             $index       the derived
     *                                                                           artifact — the promise the delivery is compared against
     * @param null|string                                           $vendorAfter the vendor after
     *                                                                           the install; in production the same tree re-read
     *
     * @return array<string, mixed>
     */
    public static function install(
        string $pedido,
        ?string $vendor = null,
        ?callable $runner = null,
        bool $dryRun = false,
        ?array $index = null,
        ?string $vendorAfter = null,
        ?string $root = null,
    ): array {
        $pedido = trim($pedido);
        if ($pedido === '') {
            return ['ok' => false, 'error' => 'missing `capability`: which one'];
        }

        // The «after» is the same tree except in tests, where the before and the after of an
        // install must be allowed to differ — because in real life they do.
        $vendorAfter ??= $vendor;

        $estado = self::state($vendor, $index);

        // ALREADY THERE IS NOT AN ERROR. Someone who asks twice is told it is done, not that it
        // failed — a failure reads as "this cannot be had" and sends them looking for another way.
        foreach ($estado['installed'] as $puesta) {
            if ($puesta['package'] === $pedido || $puesta['id'] === $pedido) {
                return ['ok' => true, 'capability' => $puesta['package'], 'hint' => 'already installed — nothing to do'];
            }
        }

        $objetivo = null;
        foreach ($estado['available'] as $falta) {
            // BY PACKAGE OR BY ID: `identity` is how the capability is named everywhere else the app
            // speaks of it; making the human translate it to `milpa/auth` was one decision too many.
            if ($falta['package'] === $pedido || ($falta['id'] ?? null) === $pedido) {
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

        // DOS COMANDOS, PORQUE SON DOS COSAS (greenhouse decisions/0241).
        //
        // El catálogo ofrece lo que un HUMANO O UN AGENTE debe teclear: la puerta gobernada. Lo que
        // esta operación EJECUTA es el `composer require` que trae el paquete. Eran la misma cadena, y
        // al volver gobernada la del catálogo esta línea se volvió recursiva —`capabilities:enable`
        // ejecutándose a sí misma— hasta que la suite lo cazó. Se arma aquí, del nombre del paquete
        // que ya se resolvió, en vez de heredarse de una entrada que existe para mostrarse.
        $comando = 'composer require ' . (string) $objetivo['package'];

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
        // LO QUE COMPOSER ESCRIBIÓ CLAVA LA CASA, y hay que corregirlo aquí porque aquí se causó.
        //
        // `composer require milpa/data` graba `^0.3.1`, que en un paquete 0.x significa
        // `>=0.3.1 <0.4.0`: un techo de MINOR. Esta familia corta minors constantemente —`live-web`
        // pasó de 0.19 a 0.23 en un día— así que instalar una capacidad dejaba a la casa fuera de
        // todos sus minors siguientes, en silencio y sin que nada fallara: composer simplemente ya no
        // ofrece la combinación (greenhouse decisions/0252).
        $ensanchado = self::widenFirstPartyPin((string) $objetivo['package']);

        $llego = self::unlocksOf((string) $objetivo['package'], $vendorAfter);
        $delivered0 = self::declaredBy($vendorAfter)[(string) $objetivo['package']] ?? null;
        if ($delivered0 === null) {
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

        // The capability's operations must be DECLARED to project — composer landed the code, but a
        // third-party package's provider does not register itself (its ops live in the package, not in
        // app-runtime's gated list). The capability names its providers; enable writes them.
        // THE RUNNING LOADER LEARNS THE TREE COMPOSER JUST WROTE: the booted autoloader caches its maps (and
        // its misses) for the life of the process, so a provider installed a moment ago would stay
        // «class not found» for the very sequence that installed it — and its next step UNJUDGEABLE
        // (greenhouse decisions/0226, measured on cattle). A fresh loader over the new maps, prepended.
        self::teachTheRunningLoader($vendorAfter ?? self::raizDeLaApp() . '/vendor');
        $root ??= self::raizDeLaApp();
        $registered = self::registerOperations($root, array_values(array_filter(
            (array) ($delivered0['operations'] ?? []),
            static fn ($c): bool => \is_string($c) && $c !== '',
        )));
        // THE DOOR, DECLARED: a capability that brings one of this package's plugins gets it named in
        // config/plugins.php, and identity gets its relying party declared — the enable that leaves the
        // human three hand edits away from the door has not enabled anything (decisions/0216, F6).
        $deliveredId = \is_string($delivered0['id'] ?? null) ? $delivered0['id'] : '';
        $announced = self::pluginsDeclaredBy($delivered0);
        $pluginsDeclared = self::registerPlugins($root, [
            ...self::pluginsUnlockedBy($deliveredId),
            ...$announced['plugins'],
        ]);
        $relyingParty = $deliveredId === 'identity' ? self::declareRelyingParty($root) : null;

        $okOut = [
            'ok' => true,
            'capability' => $objetivo['package'],
            'command' => $comando,
            'registered' => $registered,
            'plugins_declared' => $pluginsDeclared,
            // A plugin the manifest declared and the house REFUSED to wire, with why. Silence here
            // would read as «nothing to declare» on a package that declared something wrong.
            'plugins_refused' => $announced['refused'],
            // WHAT IT UNLOCKED, read AFTER installing — the package could not declare anything
            // before it was on disk, so reading `$objetivo` here would always return an empty list:
            // a field that is always empty is the same defect this repo keeps finding, something
            // declared that never lands.
            // SAID, NOT DONE QUIETLY. The install changed a line in composer.json beyond what
            // `composer require` writes, so the result names the constraint it left behind.
            'pinned' => $ensanchado,
            'unlocked' => $llego,
            'hint' => $deliveredId === 'identity'
                ? 'the passkey door is declared: run `coa serve`, open http://localhost:8000/webauthn/enroll and enroll the first key'
                : 'run `coa list` to see the new operations',
        ];
        if ($relyingParty !== null) {
            $okOut['relying_party'] = $relyingParty;
        }

        // ── THE PROMISE IS COMPARED WITH THE DELIVERY, and any difference is RECORDED ────────────
        //
        // The index holds what the registry PROMISED for this package; `installed.json` holds what
        // ARRIVED. By GOV-11 a package's declaration about itself is a claim, not a classification —
        // so a difference does not refuse (deciding what to do about it has no evidence yet, and is
        // deferred SAID), but it never passes in silence either: the chain-of-supply risk gets its
        // record here, which is what makes the question answerable later.
        $promise = \is_array($index['capabilities'][(string) $objetivo['package']] ?? null)
            ? $index['capabilities'][(string) $objetivo['package']]
            : null;
        if ($promise !== null) {
            $okOut['promised_version'] = \is_string($promise['version'] ?? null) ? $promise['version'] : '';

            $contract = static fn (array $c): array => [
                'id' => $c['id'] ?? null,
                'provides' => \is_array($c['provides'] ?? null) ? array_values($c['provides']) : [],
                'unlocks' => \is_array($c['unlocks'] ?? null) ? array_values($c['unlocks']) : [],
            ];
            [$promised, $delivered] = [$contract($promise), $contract($delivered0)];
            if ($promised !== $delivered) {
                $okOut['promise_mismatch'] = ['promised' => $promised, 'delivered' => $delivered];
            }
        }

        return $okOut;
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
