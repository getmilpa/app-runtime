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

use Milpa\AppRuntime\Web\Live\GateCeremonyAssets;
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
    /**
     * La selección que la ceremonia CONSTRUYE, corriendo su módulo de verdad.
     *
     * Antes esto leía la plantilla: el servidor deletreaba el objeto como código y este helper
     * grepeaba la cadena emitida. El objeto lo arma el módulo ahora, así que la propiedad se prueba
     * EJECUTÁNDOLO — que es lo único que contesta si omitir una llave es distinto de mandarla en
     * `null` (greenhouse decisions/0263). Sin `node` no se mide: se salta, porque medir menos es
     * mejor que decir que se midió.
     *
     * @return array<string, mixed>
     */
    private function selection(?string $attachment): array
    {
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('no node on this box: the module cannot be run, so nothing is claimed');
        }
        $module = GateCeremonyAssets::path(GateCeremonyAssets::MODULE);
        self::assertNotNull($module, 'the package ships the module its pages declare');

        $harness = \sprintf(
            // A DOM stub with nothing in it: the seam is set before the module looks for a page, so a
            // harness with no ceremony on it can still ask this house what it admits.
            'globalThis.document = { getElementById: () => null, querySelector: () => null, addEventListener: () => {} };'
            . 'require(%s);'
            . 'process.stdout.write(JSON.stringify(globalThis.MilpaGateCeremony.selectionFor(%s)));',
            json_encode($module, \JSON_THROW_ON_ERROR),
            json_encode($attachment, \JSON_THROW_ON_ERROR),
        );
        $said = (string) @shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($harness) . ' 2>&1');
        $read = json_decode($said, true);
        self::assertIsArray($read, "the module did not answer with a selection:\n" . $said);

        return $read;
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

        self::assertArrayNotHasKey('authenticatorAttachment', $selection, 'declaring nothing says nothing');
        self::assertSame(['userVerification' => 'required', 'residentKey' => 'discouraged'], $selection);
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
            ['userVerification' => 'required', 'residentKey' => 'discouraged', 'authenticatorAttachment' => 'cross-platform'],
            $this->selection('cross-platform'),
        );
    }

    /** Y una casa puede pedir lo contrario: sólo el autenticador del propio dispositivo. */
    public function testAHouseCanAlsoAskForThePlatformAuthenticatorOnly(): void
    {
        self::assertSame('platform', $this->selection('platform')['authenticatorAttachment']);
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
            self::assertSame(
                'required',
                $this->selection($attachment)['userVerification'] ?? null,
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
