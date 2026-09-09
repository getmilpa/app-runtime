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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\PasskeyController;
use PHPUnit\Framework\TestCase;

/**
 * La puerta admite el passkey que la persona ya tiene (greenhouse decisions/0244).
 *
 * `authenticatorAttachment: 'cross-platform'` estaba escrito en el código y EXCLUÍA el autenticador
 * de plataforma —Touch ID, Windows Hello, la huella del teléfono— y a los gestores de contraseñas:
 * los dos sitios donde vive un passkey en una máquina que casi cualquiera ya tiene. `evidence/0486`
 * lo llamó «el enroll PREFIERE la YubiKey», y la grieta está en el verbo: WebAuthn no sabe preferir
 * un attachment.
 */
final class TheGateAcceptsTheKeyPeopleHaveTest extends TestCase
{
    /** La selección que la página emite, con la casa declarando `$attachment` o nada. */
    private function selection(?string $attachment): string
    {
        $controller = (new \ReflectionClass(PasskeyController::class))->newInstanceWithoutConstructor();
        foreach (['rpId' => 'localhost', 'gateScope' => 'milpa.admin', 'authenticatorAttachment' => $attachment] as $name => $value) {
            $property = (new \ReflectionClass($controller))->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }
        $method = new \ReflectionMethod($controller, 'enrollHtml');
        $method->setAccessible(true);
        preg_match('/authenticatorSelection: \{[^}]*\}/', (string) $method->invoke($controller), $found);

        return $found[0] ?? '';
    }

    /**
     * F1 — sin declarar nada, la propiedad NO SE EMITE.
     *
     * Omitirla no es lo mismo que mandarla en `null`: el navegador lee la segunda como una
     * restricción que ningún autenticador satisface, así que la casa que no declara nada tiene que
     * no decir nada.
     */
    public function testWithNothingDeclaredTheAttachmentIsNotEmittedAtAll(): void
    {
        $selection = $this->selection(null);

        self::assertStringNotContainsString('authenticatorAttachment', $selection);
        self::assertStringNotContainsString('null', $selection);
        self::assertSame("authenticatorSelection: { userVerification: 'required', residentKey: 'discouraged' }", $selection);
    }

    /**
     * F2 con CONTROL POSITIVO — el comportamiento anterior sigue disponible, declarándolo.
     *
     * Esta rebanada quita una ley, no una capacidad: una casa que quiera sólo hardware escribe una
     * línea y recupera exactamente lo que `evidence/0486` cableó.
     */
    public function testAHouseThatWantsHardwareOnlyDeclaresItAndGetsExactlyWhatItHadBefore(): void
    {
        self::assertSame(
            "authenticatorSelection: { authenticatorAttachment: 'cross-platform', userVerification: 'required', residentKey: 'discouraged' }",
            $this->selection('cross-platform'),
        );
    }

    /** Y una casa puede pedir lo contrario: sólo el autenticador del propio dispositivo. */
    public function testAHouseCanAlsoAskForThePlatformAuthenticatorOnly(): void
    {
        self::assertStringContainsString("authenticatorAttachment: 'platform'", $this->selection('platform'));
    }

    /**
     * F4, EL QUE PUEDE DECIR QUE NO: la verificación de usuario sobrevive en las tres formas.
     *
     * Si al ensanchar la puerta se hubiera relajado esto, la rebanada habría DEGRADADO la ceremonia
     * en vez de ensancharla — y ésa era la ganancia real de `evidence/0486`. Un passkey de plataforma
     * o de gestor satisface `required` con biometría o PIN; nada aquí se afloja para que entren.
     */
    public function testUserVerificationStaysRequiredInEveryConfiguration(): void
    {
        foreach ([null, 'platform', 'cross-platform'] as $attachment) {
            self::assertStringContainsString(
                "userVerification: 'required'",
                $this->selection($attachment),
                'widening the door must not lower it',
            );
        }
    }

    /** Y el enroll deja de prometer lo que el código prohibía. */
    public function testTheCopyNoLongerPromisesWhatTheCodeRefused(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Web/Controllers/PasskeyController.php');

        self::assertStringNotContainsString(
            "Enroll this device's authenticator",
            $source,
            'the page said «this device» while the code excluded exactly that',
        );
    }
}
