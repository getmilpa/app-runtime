<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Config\SecretOverlay;
use Milpa\AppRuntime\Operations\ConfigOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use PHPUnit\Framework\TestCase;

/**
 * `provider:declare` — a credential written where the code reads and never where git looks.
 *
 * A Milpa app had nowhere to put one: `milpa/framework` ships no `.env`, loads no `.env` and does not
 * ignore one, and its two config homes are both committed. So this could not exist until
 * {@see SecretOverlay} did, and shipping it first would have made the house say «declared» about a
 * value that either went to git or that no request could read (greenhouse decisions/0267).
 */
final class ProviderDeclareTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-prov-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ([SecretOverlay::RUTA, '/.gitignore'] as $f) {
            @unlink($this->root . $f);
        }
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root . '/.git');
        @rmdir($this->root);
    }

    /**
     * 🚨 IT REFUSES BEFORE IT LEAKS, and it names the exact line.
     *
     * A credential written into a repository that would commit it is not a mistake to warn about
     * afterwards: by then the value is in the working tree. The honest moment to stop is before, and
     * «configure your gitignore» is not an instruction — the line is.
     */
    public function testInARepositoryThatWouldCommitItNothingIsWritten(): void
    {
        mkdir($this->root . '/.git', 0o700, true);

        $out = $this->declare(['key' => 'agent.apiKey', 'value' => 'sk-must-not-land']);

        self::assertFalse($out['ok']);
        self::assertStringContainsString('would commit its secrets file', $out['error']);
        self::assertSame(SecretOverlay::IGNORE_LINE, $out['add_to_gitignore'], 'the line, not advice');
        self::assertFileDoesNotExist($this->root . SecretOverlay::RUTA, 'and not one byte was written');
    }

    /** Outside a repository nothing would commit it, so nothing is missing. */
    public function testOutsideARepositoryItWrites(): void
    {
        $out = $this->declare(['key' => 'agent.apiKey', 'value' => 'sk-lives-here']);

        self::assertTrue($out['ok']);
        self::assertSame(['agent.apiKey'], $out['holds']);
        self::assertFileExists($this->root . SecretOverlay::RUTA);
    }

    /**
     * 🚨 THE RESULT NEVER CARRIES THE VALUE, and this looks for it everywhere in the answer.
     *
     * An operation that returned what it wrote would put a key in a terminal's scrollback, an event
     * ledger, and whatever surface projected the result. That is the same reason `SecretOverlay` has
     * no reader that can print one — a leak through the WRITER would make the reader's discipline
     * pointless.
     */
    public function testNothingInTheAnswerContainsTheCredential(): void
    {
        $out = $this->declare(['key' => 'agent.apiKey', 'value' => 'sk-never-echoed-anywhere']);

        self::assertTrue($out['ok']);
        self::assertStringNotContainsString(
            'sk-never-echoed-anywhere',
            (string) json_encode($out),
            'not in a value, not in a message, not in a hint',
        );
        // And it IS on disk — otherwise this test would pass on an operation that wrote nothing.
        self::assertStringContainsString('sk-never-echoed-anywhere', (string) file_get_contents($this->root . SecretOverlay::RUTA));
    }

    /** The file is created 0600, and narrowed BEFORE the bytes rather than after. */
    public function testTheFileIsUnreadableToAnybodyElse(): void
    {
        $this->declare(['key' => 'agent.apiKey', 'value' => 'sk-x']);

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->root . SecretOverlay::RUTA)), -4));
    }

    /** Writing one credential erases no other, and `forget` removes only what it names. */
    public function testDeclaringAndForgettingTouchOnlyTheirOwnPath(): void
    {
        $this->declare(['key' => 'agent.apiKey', 'value' => 'a']);
        $this->declare(['key' => 'mercure.publisher_key', 'value' => 'b']);
        self::assertSame(['agent.apiKey', 'mercure.publisher_key'], SecretOverlay::declared($this->root));

        $out = $this->declare(['key' => 'agent.apiKey', 'forget' => true]);

        self::assertTrue($out['ok']);
        self::assertFalse($out['declared']);
        self::assertSame(['mercure.publisher_key'], SecretOverlay::declared($this->root), 'the neighbour survived');
        self::assertStringContainsString('revoke it there too', $out['note'], 'forgetting a declaration is not revoking a key');
    }

    /** A credential declared empty is a credential nobody can use, so it is refused. */
    public function testAnEmptyValueIsRefusedAndAPathThatIsNotOneToo(): void
    {
        self::assertFalse($this->declare(['key' => 'agent.apiKey'])['ok']);
        self::assertFalse($this->declare(['key' => 'agent.apiKey', 'value' => ''])['ok']);
        self::assertFalse($this->declare(['key' => '', 'value' => 'x'])['ok']);
        self::assertFalse($this->declare(['key' => '../escape', 'value' => 'x'])['ok'], 'a path is dotted, not a filesystem walk');
        self::assertFileDoesNotExist($this->root . SecretOverlay::RUTA);
    }

    /**
     * TWO AXES DIFFER FROM `config:set`, and both are the honest classification.
     *
     * `privileged` because declaring a credential gives this app the ability to act as you at
     * somebody else's service and spend what it charges — not the same gate as a compaction
     * setting. `manual_recovery` because nothing here can read a secret out, so overwriting one
     * destroys the only copy; getting it back means getting a new key from the provider.
     *
     * And `externality: none` is deliberate: writing this file reaches nobody. It ENABLES egress
     * later, which `agent:model` declares when it goes out — conflating «I stored a key» with «I
     * called somebody» would put every provider's uptime inside this ceiling.
     */
    public function testItsCeilingSaysWhatDeclaringACredentialActuallyIs(): void
    {
        $op = self::declared();

        self::assertSame(Authority::Privileged, $op->effects?->authority);
        self::assertSame(Reversibility::ManualRecovery, $op->effects?->reversibility);
        self::assertSame(Externality::None, $op->effects?->externality, 'storing a key calls nobody');
        self::assertSame(['id'], $op->effects?->escalatesOn);
        self::assertTrue($op->effects?->isFullyClassified());
        self::assertStringContainsString('cannot be read back', (string) $op->effects?->rollbackContract);
    }

    /**
     * 🚨 THE GATE ITSELF SAYS YES — asked of `Consent`, not of a flag this class sets.
     *
     * Measured on cattle before this held: `provider:declare --key=agent.apiKey --value=…` WROTE THE
     * CREDENTIAL with no signature, while `config:set`, carrying a lighter ceiling, answered «this
     * operation mutates and needs your authorization». Ruling out the wrong causes cost three
     * measurements — `requiresConfirmation` (both no), `authority` (both privileged), then
     * `mutating: true`, which printed in the contract and STILL wrote (greenhouse decisions/0267).
     *
     * The answer was in `Consent::demanded()`: rule S2 asks for a signature when subject >=
     * Executable AND authority >= Privileged. Declaring a credential is honestly `Configuration` —
     * the same classes keep loading, they act differently — and that axis's own docblock says the
     * level is «neither the kind of act a signature is for». `capabilities:enable` is signed because
     * it is `Executable`: it changes WHICH CODE WILL RUN. So the ceiling was never going to fire, and
     * raising the subject to borrow the gate would have put a lie in the ceiling to get a behaviour.
     *
     * THE EFFECT AXES MEASURE THE CHANGE TO THIS HOUSE, AND A CREDENTIAL'S DANGER IS ALL OUTSIDE IT.
     * `externality: none` is right — writing the file calls nobody — and that is exactly why the axes
     * cannot see what the act hands over: the power to act as somebody at another service, and to
     * spend what it charges. `requiresConfirmation` is the declared lever for that, read FIRST by
     * `Consent::demanded()`, before S2.
     *
     * Asked of the gate rather than the flag, deliberately: somebody who changes how consent is
     * decided sees this fail, and a flag renamed under a passing assertion would not.
     */
    public function testTheConsentGateDemandsASignatureBeforeAKeyIsWritten(): void
    {
        $op = self::declared();

        self::assertTrue(
            Consent::demanded($op, ['key' => 'agent.apiKey', 'value' => 'sk-x']),
            'without this the operation writes a credential unsigned — measured, not supposed',
        );
        // The control: the ceiling ALONE does not demand it, which is why the flag is here at all.
        self::assertNotSame(Subject::Executable, $op->effects?->subject, 'and its subject is honestly configuration');
    }

    /**
     * @param array<string, mixed> $input
     *
     * 🚨 THROUGH `para()`, THE NAMED SEAM — and two wrong turns got here.
     *
     * This class has NO CONSTRUCTOR, deliberately: its docblock says a `__construct(?string $root)`
     * raises a TypeError when the dispatcher hands it the container, and «the whole catalogue stops
     * building, so the app loses every command it has». Which means `new ConfigOperations($root)`
     * ACCEPTS THE ARGUMENT AND IGNORES IT — PHP says nothing — so the root stayed null, `raiz()` fell
     * back to the autoloader's location, and the operation refused about THIS PACKAGE's repository
     * while the test believed it was pointing at a temporary directory (greenhouse decisions/0267).
     */
    private function declare(array $input): array
    {
        $method = new \ReflectionMethod(ConfigOperations::class, 'declareSecret');
        $method->setAccessible(true);

        return $method->invoke(ConfigOperations::para($this->root), $input);
    }

    private static function declared(): Operation
    {
        foreach (ConfigOperations::para()->operations() as $op) {
            if ($op->name === 'provider:declare') {
                return $op;
            }
        }

        self::fail('provider:declare is not declared');
    }
}
