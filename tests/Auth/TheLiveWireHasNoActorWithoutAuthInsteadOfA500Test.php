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

namespace Milpa\AppRuntime\Tests\Auth;

use Milpa\AppRuntime\Auth\LivePrincipal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * In an app WITHOUT the optional `milpa/auth`, the live wire has an anonymous caller — not a 500.
 *
 * That is what this class always promised («no actor → no principal → an action the component
 * restricts is denied») and could not keep: it read the request attribute through
 * `AuthenticateMiddleware::ATTRIBUTE`, and a constant fetch on an absent class throws. Measured on
 * fresh cattle serving a screen `screen:declare` had just reported as `served`: HTTP 500,
 * «Class "Milpa\Auth\Http\AuthenticateMiddleware" not found» (greenhouse `evidence/0993`). Four
 * controllers enter here, so the whole live wire was unusable in an app that had not opted in.
 *
 * ── WHY A CHILD PROCESS ─────────────────────────────────────────────────────────────────────────
 *
 * `milpa/auth` IS installed in this suite (require-dev), so calling the method here proves nothing
 * about an app where it is absent: the same call returned null before the fix too. The absence has
 * to be real, so a child process prepends an autoloader that THROWS for any `Milpa\Auth\` class.
 * Reading the attribute by name never asks for one; reading it through the class does, and the
 * throw is the failure. `instanceof` on a missing class answers false without autoloading, which
 * is why the narrowing below it did not have to change.
 *
 * @guards the live wire's anonymous path in an app without milpa/auth
 *
 * @fires  on every live render and every live action
 *
 * @refuses nothing — it is the absence of a refusal that was the defect
 *
 * @subject-in milpa/app-runtime
 */
#[CoversClass(LivePrincipal::class)]
final class TheLiveWireHasNoActorWithoutAuthInsteadOfA500Test extends TestCase
{
    public function testAnAnonymousRequestGetsNoPrincipalWithoutReachingForMilpaAuth(): void
    {
        [$code, $output] = $this->inAnAppWithoutAuth('$principal = Milpa\AppRuntime\Auth\LivePrincipal::fromRequest($request);
            echo $principal === null ? "NULL_PRINCIPAL" : "PRINCIPAL";');

        self::assertSame(0, $code, "the child process failed:\n" . $output);
        self::assertStringContainsString('NULL_PRINCIPAL', $output, 'an anonymous caller has no principal');
        self::assertStringNotContainsString('REACHED_FOR', $output, 'and nothing reached for a milpa/auth class');
    }

    public function testTheProbeItselfWouldSeeAReachForMilpaAuth(): void
    {
        // THE POSITIVE CONTROL for the harness, not for the fix: if the throwing autoloader could
        // not see a reach, the test above would pass against any code at all.
        [$code, $output] = $this->inAnAppWithoutAuth('echo Milpa\Auth\Http\AuthenticateMiddleware::ATTRIBUTE;');

        self::assertNotSame(0, $code, 'reaching for the class has to fail the child');
        self::assertStringContainsString('REACHED_FOR', $output, 'and the probe has to name what was reached for');
        self::assertStringContainsString('Milpa\Auth\Http\AuthenticateMiddleware', $output);
    }

    /**
     * Runs a snippet in a child process where every `Milpa\Auth\` class is unreachable.
     *
     * @return array{0: int, 1: string} exit code and combined output
     */
    private function inAnAppWithoutAuth(string $snippet): array
    {
        $root = \dirname(__DIR__, 2);
        $script = <<<PHP
            <?php
            require '{$root}/vendor/autoload.php';

            // PREPENDED, so it is asked FIRST and the real classmap never gets the chance. This is
            // the absence of an optional package, reproduced rather than assumed.
            spl_autoload_register(static function (string \$class): void {
                if (str_starts_with(\$class, 'Milpa\\\\Auth\\\\')) {
                    fwrite(STDOUT, 'REACHED_FOR ' . \$class . "\\n");

                    throw new \\RuntimeException('Class "' . \$class . '" not found');
                }
            }, true, true);

            \$request = new Nyholm\\Psr7\\ServerRequest('GET', '/live/page?component=probe');
            {$snippet}
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'milpa-live-') . '.php';
        file_put_contents($file, $script);

        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        @unlink($file);

        return [$code, implode("\n", $output)];
    }
}
