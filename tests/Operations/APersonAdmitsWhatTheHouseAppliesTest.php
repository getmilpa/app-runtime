<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\AppliedTrials;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which operations a house applies on its own is a person's signed act in that house (greenhouse decisions/0586).
 *
 * The house lands the verified trial of an admitted operation without any call of the model asking for it. What
 * CAN be admitted is four operations, decided one by one; what IS admitted in a house is said by a person of it,
 * with `sandbox:admit`, and unsaid with `sandbox:withdraw` — never by a seat, never by a default. A house is born
 * with none.
 *
 * @guards an admission written with who decided and when, only under a signature that covers exactly that call;
 *         a withdrawal that lays who withdrew over the entry and keeps it; a list anybody can read back; a house
 *         born with none; the three operations kept off the surface a seat works through, and out of any trial
 *
 * @refuses an admission without the signature that names who decides, or under a signature for another call; an
 *          operation that is not one of the four; a list written by hand admitting a fifth
 *
 * @subject-in milpa/app-runtime
 */
final class APersonAdmitsWhatTheHouseAppliesTest extends TestCase
{
    private const PERSON = '1111111111111111111111111111111111111111';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-admit-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAHouseIsBornWithNone(): void
    {
        $listed = $this->call('sandbox:admitted', []);

        self::assertTrue($listed['ok']);
        self::assertSame([], $listed['admitted']);
        self::assertSame(['plugins.register', 'entity:seed', 'make what=page', 'make what=plugin'], $listed['admissible']);
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH, 'reading the list writes nothing');
    }

    public function testAPersonAdmitsAnOperationWithASignatureOverExactlyThatCall(): void
    {
        $admitted = $this->call('sandbox:admit', ['operation' => 'plugins.register'], signed: true);

        self::assertTrue($admitted['ok'], (string) ($admitted['error'] ?? ''));
        self::assertSame('plugins.register', $admitted['operation']);
        self::assertSame('key:' . self::PERSON, $admitted['admitted_by']);
        self::assertSame(['plugins.register'], $admitted['admitted']);
        self::assertStringContainsString('From the next leg', $admitted['note']);

        $entry = AppliedTrials::forRoot($this->root)->admitted()['plugins.register'];
        self::assertSame('key:' . self::PERSON, $entry['admitted_by']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $entry['admitted_at']);
        self::assertSame(['plugins.register' => $entry], $this->call('sandbox:admitted', [])['admitted']);
    }

    public function testMakeIsAdmittedForOneThingItMakesAtATime(): void
    {
        self::assertTrue($this->call('sandbox:admit', ['operation' => 'make', 'what' => 'page'], signed: true)['ok']);

        $list = AppliedTrials::forRoot($this->root);
        self::assertTrue($list->admits('make what=page'));
        self::assertFalse($list->admits('make what=plugin'), 'one admission, one thing');
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('unsigned')]
    public function testWithoutTheSignatureThatNamesWhoDecidesNothingIsWritten(string $operation, array $input, ?array $signedCall, string $signedOperation = ''): void
    {
        if ($operation === 'sandbox:withdraw') {
            AppliedTrials::forRoot($this->root)->admit('plugins.register', 'key:' . self::PERSON, '2026-10-07T00:00:00+00:00');
        }
        $before = @file_get_contents($this->root . '/' . AppliedTrials::PATH);

        $result = $this->call($operation, $input, signed: $signedCall !== null, signedCall: $signedCall, signedOperation: $signedOperation);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('signature', $result['error']);
        self::assertStringContainsString('nothing was written', $result['error']);
        self::assertSame($before, @file_get_contents($this->root . '/' . AppliedTrials::PATH));
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>|null, 3?: string}> */
    public static function unsigned(): iterable
    {
        yield 'admit, no signature' => ['sandbox:admit', ['operation' => 'plugins.register'], null];
        yield 'admit, a signature over another operation name' => ['sandbox:admit', ['operation' => 'plugins.register'], ['operation' => 'entity:seed']];
        yield 'admit, a signature over make of something else' => ['sandbox:admit', ['operation' => 'make', 'what' => 'page'], ['operation' => 'make', 'what' => 'plugin']];
        yield 'admit, a signature for a withdrawal' => ['sandbox:admit', ['operation' => 'plugins.register'], ['operation' => 'plugins.register'], 'sandbox:withdraw'];
        yield 'admit, a signature for a grant' => ['sandbox:admit', ['operation' => 'plugins.register'], ['operation' => 'plugins.register'], 'identity:grant'];
        yield 'withdraw, no signature' => ['sandbox:withdraw', ['operation' => 'plugins.register'], null];
        yield 'withdraw, a signature for an admission' => ['sandbox:withdraw', ['operation' => 'plugins.register'], ['operation' => 'plugins.register'], 'sandbox:admit'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('notOneOfTheFour')]
    public function testOnlyTheFourCanBeAdmitted(array $input): void
    {
        $result = $this->call('sandbox:admit', $input, signed: true);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('cannot be admitted', $result['error']);
        self::assertStringContainsString('plugins.register, entity:seed, make what=page, make what=plugin', $result['error'], 'it says which can');
        self::assertStringContainsString('nothing was written', $result['error']);
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function notOneOfTheFour(): iterable
    {
        yield 'a screen a visitor sees' => [['operation' => 'screen:declare']];
        yield 'authoring' => [['operation' => 'implement']];
        yield 'an edit' => [['operation' => 'edit']];
        yield 'a component' => [['operation' => 'component:define']];
        yield 'make of a test' => [['operation' => 'make', 'what' => 'test']];
        yield 'all of make' => [['operation' => 'make']];
        yield 'a what on an operation that makes one thing' => [['operation' => 'plugins.register', 'what' => 'page']];
        yield 'the name the tool has, not the operation' => [['operation' => 'plugins_register']];
        yield 'no operation' => [[]];
        yield 'a what that is not a word' => [['operation' => 'make', 'what' => ['page']]];
    }

    public function testAdmittingTwiceWritesOnce(): void
    {
        self::assertTrue($this->call('sandbox:admit', ['operation' => 'entity:seed'], signed: true)['ok']);
        $first = file_get_contents($this->root . '/' . AppliedTrials::PATH);

        $again = $this->call('sandbox:admit', ['operation' => 'entity:seed'], signed: true, signer: '2222222222222222222222222222222222222222');

        self::assertFalse($again['ok']);
        self::assertStringContainsString('already admitted', $again['error']);
        self::assertSame($first, file_get_contents($this->root . '/' . AppliedTrials::PATH), 'who admitted it first stays');
    }

    public function testAWithdrawalLaysWhoWithdrewOverTheEntryAndKeepsIt(): void
    {
        $this->call('sandbox:admit', ['operation' => 'plugins.register'], signed: true);
        $this->call('sandbox:admit', ['operation' => 'entity:seed'], signed: true);

        $withdrawn = $this->call('sandbox:withdraw', ['operation' => 'plugins.register'], signed: true, signer: '2222222222222222222222222222222222222222');

        self::assertTrue($withdrawn['ok'], (string) ($withdrawn['error'] ?? ''));
        self::assertSame('key:2222222222222222222222222222222222222222', $withdrawn['withdrawn_by']);
        self::assertSame(['entity:seed'], $withdrawn['admitted']);
        self::assertStringContainsString('From the next call', $withdrawn['note']);
        $list = AppliedTrials::forRoot($this->root);
        self::assertFalse($list->admits('plugins.register'));
        self::assertTrue($list->admits('entity:seed'), 'the other admission is not touched');
        $kept = $list->record()['plugins.register'];
        self::assertSame('key:' . self::PERSON, $kept['admitted_by'], 'who admitted it is still on record');
        self::assertSame('key:2222222222222222222222222222222222222222', $kept['withdrawn_by']);
        self::assertSame(['plugins.register' => $kept], $this->call('sandbox:admitted', [])['withdrawn'], 'what is still admitted is not listed as withdrawn');
    }

    public function testWhatWasWithdrawnCanBeAdmittedAgain(): void
    {
        $this->call('sandbox:admit', ['operation' => 'plugins.register'], signed: true);
        $this->call('sandbox:withdraw', ['operation' => 'plugins.register'], signed: true);

        self::assertTrue($this->call('sandbox:admit', ['operation' => 'plugins.register'], signed: true, signer: '2222222222222222222222222222222222222222')['ok']);

        $entry = AppliedTrials::forRoot($this->root)->record()['plugins.register'];
        self::assertSame('key:2222222222222222222222222222222222222222', $entry['admitted_by']);
        self::assertArrayNotHasKey('withdrawn_by', $entry);
    }

    public function testWithdrawingWhatIsNotAdmittedWritesNothing(): void
    {
        $result = $this->call('sandbox:withdraw', ['operation' => 'plugins.register'], signed: true);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('is not admitted in this house', $result['error']);
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH);
    }

    public function testAListWrittenByHandAdmitsNothingThatCannotBeAdmitted(): void
    {
        mkdir(\dirname($this->root . '/' . AppliedTrials::PATH), 0o755, true);
        $entry = ['admitted_by' => 'key:' . self::PERSON, 'admitted_at' => '2026-10-07T00:00:00+00:00'];
        file_put_contents($this->root . '/' . AppliedTrials::PATH, json_encode(['screen:declare' => $entry, 'make' => $entry, 'make what=test' => $entry, 'entity:seed' => $entry, 'plugins.register' => ['admitted_at' => 'x'], 'make what=plugin' => ['admitted_by' => 'key:' . self::PERSON], 'make what=page' => 'yes']));

        self::assertSame(['entity:seed'], array_keys(AppliedTrials::forRoot($this->root)->admitted()), 'the four are the ceiling, and an entry says who and when or it is not one');

        file_put_contents($this->root . '/' . AppliedTrials::PATH, '{"entity:seed": ');
        self::assertSame([], AppliedTrials::forRoot($this->root)->admitted(), 'a list that cannot be read admits nothing');
    }

    public function testAListThatCannotBeWrittenSaysSoAndAdmitsNothing(): void
    {
        file_put_contents($this->root . '/storage', 'not a directory');

        $result = $this->call('sandbox:admit', ['operation' => 'plugins.register'], signed: true);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('nowhere to keep', $result['error']);
    }

    public function testASeatIsNeverOfferedTheseAndNoTrialRunsThem(): void
    {
        $router = new TrialRouter($this->root, new TrialRunner(), __FILE__);
        foreach (['sandbox:admit', 'sandbox:withdraw', 'sandbox:admitted'] as $name) {
            $operation = $this->operation($name);
            self::assertSame(['cli'], $operation->surfaces, $name);
            self::assertFalse($operation->supportsSurface('mcp'), "{$name}: a seat does not decide what the house does on its own");
            self::assertFalse($router->eligible($operation), "{$name} never runs in a trial");
        }
        foreach (['sandbox:admit', 'sandbox:withdraw'] as $name) {
            $operation = $this->operation($name);
            self::assertSame(['identity:enroll'], $operation->scopes, 'the scope of recognizing an identity: an institutional act');
            self::assertTrue($operation->requiresConfirmation);
            self::assertTrue($operation->mutating);
            self::assertSame(Authority::Privileged, $operation->effects?->authority);
        }
        self::assertFalse($this->operation('sandbox:admitted')->mutating);
    }

    private function operation(string $name): Operation
    {
        foreach ((new TrialOperations(new DIContainer(), root: $this->root))->operations() as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }
        self::fail("{$name} is not offered");
    }

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $signedCall what the signature covers, when it is not this very call
     *
     * @return array<string, mixed>
     */
    private function call(string $name, array $input, bool $signed = false, ?array $signedCall = null, string $signedOperation = '', string $signer = self::PERSON): array
    {
        $container = new DIContainer();
        if ($signed) {
            $authorization = new OperationAuthorization(
                operation: $signedOperation === '' ? $name : $signedOperation,
                arguments: $signedCall ?? $input,
                host: 'lab-host',
                issuedAt: '2026-10-07T00:00:00+00:00',
                nonce: 'n-1',
            );
            $container->registerService(GrantedAuthorization::class, new GrantedAuthorization(
                authorization: $authorization,
                signer: new VerifiedSigner($signer, 'Lab <lab@example.invalid>'),
                payload: $authorization->canonical(),
                signature: 'exact-signature-bytes',
            ));
        }
        foreach ((new TrialOperations($container, root: $this->root))->operations() as $operation) {
            if ($operation->name === $name) {
                return ($operation->handler)($input);
            }
        }
        self::fail("{$name} is not offered");
    }
}
