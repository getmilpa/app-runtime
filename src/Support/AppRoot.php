<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Support;

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Support\RootResolver;

/**
 * Where this app lives — asked, never guessed.
 *
 * ── WHY THIS EXISTS, AND WHAT IT COST TO LEARN ──────────────────────────────────────────────────
 *
 * Three places in this package resolved the app root as «the kernel's root, or else `getcwd()`».
 * That fallback looks harmless and is not: the framework's own instructions serve an app with
 * `php -S localhost:8000 -t public`, and PHP's built-in server **chdir()s into the docroot on every
 * request**. So the moment the kernel was not resolvable, everything those components wrote landed
 * inside `public/` — and was served.
 *
 * Measured on cattle (greenhouse evidence/0535): a passkey enrolled through the browser wrote
 * `public/var/passkey/credentials.json` and `challenges.json`, and both answered **HTTP 200**. A
 * WebAuthn challenge is a one-time nonce the server issues and consumes; publishing the pending ones
 * is not a cosmetic mistake. The same run also split the store in two — the terminal read
 * `var/passkey/`, the browser wrote `public/var/passkey/` — so the enrolment the human performed was
 * invisible to the command that grants it scopes.
 *
 * ── SO: ASK THE KERNEL, THEN WALK — NEVER TAKE THE WORKING DIRECTORY ────────────────────────────
 *
 * First the kernel, which knows. Failing that, {@see RootResolver} — the convention this platform
 * already owns, which walks UP from the working directory to the nearest `composer.json`. From
 * `public/` that lands on the app, which is the whole difference: the old fallback stopped where the
 * process happened to stand, and this one climbs to where the app actually is. It is CALLED and not
 * copied on purpose (greenhouse evidence/0141). If even that finds nothing, this throws rather than
 * inventing a directory.
 *
 * It asks the PSR-11 REGISTRY and not `DIContainerInterface::has()`, which answers true for any
 * auto-wirable class and would hand back a freshly built kernel rooted wherever that constructor
 * decided — the same trap that made an admin panel refuse a LAN request in the wrong language
 * (greenhouse evidence/0522).
 */
final class AppRoot
{
    /**
     * The app's root directory.
     *
     * @throws \RuntimeException when neither the kernel nor a `composer.json` above the working
     *                           directory can say where the app is
     */
    public static function of(DIContainerInterface $container, string $who): string
    {
        if ($container->getContainer()->has(Kernel::class)) {
            $kernel = $container->get(Kernel::class);

            if ($kernel instanceof Kernel) {
                return $kernel->root();
            }
        }

        try {
            return (new RootResolver())->resolve();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                '%s needs to know where this app lives: no %s is registered and no composer.json was found '
                . 'above the working directory. It will not fall back to the working directory itself — a '
                . 'Milpa app served with `php -S … -t public` has the DOCROOT as its working directory, so '
                . 'everything written would land inside public/ and be served, credentials and one-time '
                . 'challenges included (greenhouse evidence/0535).',
                $who,
                Kernel::class,
            ), 0, $e);
        }
    }
}
