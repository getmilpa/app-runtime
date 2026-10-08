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
        self::assertSame(['plugins.register', 'entity:seed', 'make what=page', 'make what=plugin', 'make what=operation', 'make what=entity'], $listed['admissible']);
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

    /**
     * WHOEVER FOUNDS A HOUSE ADMITS WHAT IT APPLIES, WITH ONE SIGNED ACT (greenhouse decisions/0586, amended on
     * 2026-10-08). A house is still born with none. Six signed commands were the only way to admit the six; one act
     * fits without stretching anyone's authority because it admits only what the person SAW: the list says what each
     * operation does and carries a digest of itself, and the signature has to cover that digest.
     */
    public function testTheListSaysWhatEachOperationDoesAndCarriesTheDigestOfAdmittingThemAll(): void
    {
        $listed = $this->call('sandbox:admitted', []);

        self::assertSame(AppliedTrials::admissible(), array_column($listed['what_each_does'], 'operation'), 'the six, in the list\'s order');
        foreach ($listed['what_each_does'] as $row) {
            self::assertNotSame('', trim($row['does']), $row['operation'] . ' says what it does');
        }
        self::assertSame(AppliedTrials::digestOfEverything(), $listed['everything']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $listed['everything']);
        self::assertStringContainsString('--everything=' . $listed['everything'], $listed['to_admit_everything']);
        self::assertStringContainsString('--sign', $listed['to_admit_everything']);
    }

    public function testOneSignedActAdmitsEverythingThePersonSaw(): void
    {
        $seen = $this->call('sandbox:admitted', [])['everything'];

        $result = $this->call('sandbox:admit', ['everything' => $seen], signed: true);

        self::assertTrue($result['ok']);
        self::assertSame(AppliedTrials::admissible(), $result['admitted_now']);
        self::assertSame('key:' . self::PERSON, $result['admitted_by']);
        $list = AppliedTrials::forRoot($this->root);
        foreach (AppliedTrials::admissible() as $key) {
            self::assertTrue($list->admits($key), $key);
            self::assertSame('key:' . self::PERSON, $list->admitted()[$key]['admitted_by'], 'each one keeps who admitted it, as if admitted by itself');
        }
        self::assertTrue($this->call('sandbox:withdraw', ['operation' => 'entity:seed'], signed: true)['ok'], 'and each is taken back by itself');
        self::assertFalse(AppliedTrials::forRoot($this->root)->admits('entity:seed'));
        self::assertTrue(AppliedTrials::forRoot($this->root)->admits('plugins.register'));
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('everythingNotSeen')]
    public function testWithoutTheDigestOfWhatWasSeenThePersonIsShownItAndNothingIsWritten(array $input): void
    {
        $result = $this->call('sandbox:admit', $input, signed: true);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('nothing was written', $result['error']);
        self::assertSame(AppliedTrials::admissible(), array_column($result['would_admit'], 'operation'), 'it is shown what the act would admit');
        self::assertSame(AppliedTrials::digestOfEverything(), $result['everything'], 'and what to sign');
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function everythingNotSeen(): iterable
    {
        yield 'no digest' => [['everything' => '']];
        yield 'a flag with no value' => [['everything' => true]];
        yield 'the digest of another list' => [['everything' => 'sha256:' . str_repeat('0', 64)]];
        yield 'a word' => [['everything' => 'yes']];
    }

    public function testTheSignatureHasToCoverThatDigestAndNothingElse(): void
    {
        $seen = AppliedTrials::digestOfEverything();

        $unsigned = $this->call('sandbox:admit', ['everything' => $seen]);
        self::assertFalse($unsigned['ok']);
        self::assertStringContainsString('--sign', $unsigned['error']);
        $other = $this->call('sandbox:admit', ['everything' => $seen], signed: true, signedCall: ['operation' => 'plugins.register']);
        self::assertFalse($other['ok']);
        self::assertStringContainsString('does not cover', $other['error']);
        $withdraw = $this->call('sandbox:admit', ['everything' => $seen], signed: true, signedOperation: 'sandbox:withdraw');
        self::assertFalse($withdraw['ok']);
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH);
    }

    public function testEverythingAndOneOperationAreNotOneAct(): void
    {
        $both = $this->call('sandbox:admit', ['everything' => AppliedTrials::digestOfEverything(), 'operation' => 'plugins.register'], signed: true);

        self::assertFalse($both['ok']);
        self::assertStringContainsString('not both', $both['error']);
        self::assertStringContainsString('nothing was written', $both['error']);
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH);
    }

    public function testWhatWasAlreadyAdmittedKeepsWhoAdmittedItAndAdmittingEverythingTwiceWritesOnce(): void
    {
        self::assertTrue($this->call('sandbox:admit', ['operation' => 'entity:seed'], signed: true, signer: 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555')['ok']);
        $seen = AppliedTrials::digestOfEverything();

        $result = $this->call('sandbox:admit', ['everything' => $seen], signed: true);

        self::assertTrue($result['ok']);
        self::assertSame(array_values(array_diff(AppliedTrials::admissible(), ['entity:seed'])), $result['admitted_now'], 'only what was not admitted yet');
        $admitted = AppliedTrials::forRoot($this->root)->admitted();
        self::assertSame('key:AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555', $admitted['entity:seed']['admitted_by'], 'who admitted it first is who admitted it');
        self::assertSame('key:' . self::PERSON, $admitted['make what=operation']['admitted_by']);
        $before = file_get_contents($this->root . '/' . AppliedTrials::PATH);

        $again = $this->call('sandbox:admit', ['everything' => $seen], signed: true);
        self::assertFalse($again['ok']);
        self::assertStringContainsString('already admitted', $again['error']);
        self::assertSame($before, file_get_contents($this->root . '/' . AppliedTrials::PATH));
    }

    public function testAnAdmissionIsTakenBackOneAtATime(): void
    {
        $seen = AppliedTrials::digestOfEverything();
        self::assertTrue($this->call('sandbox:admit', ['everything' => $seen], signed: true)['ok']);

        $result = $this->call('sandbox:withdraw', ['everything' => $seen], signed: true);

        self::assertFalse($result['ok'], 'taking back is one operation at a time: what the house applied meanwhile is not one thing');
        self::assertStringContainsString('one operation at a time', $result['error']);
        self::assertCount(\count(AppliedTrials::admissible()), AppliedTrials::forRoot($this->root)->admitted());
    }

    public function testTheDigestIsOfTheListAndOfWhatEachDoes(): void
    {
        self::assertSame(AppliedTrials::digestOfEverything(), AppliedTrials::digestOfEverything());
        self::assertSame('sha256:' . hash('sha256', (string) json_encode(AppliedTrials::whatEachDoes())), AppliedTrials::digestOfEverything(), 'of exactly what the person is shown');
        self::assertSame(AppliedTrials::admissible(), array_column(AppliedTrials::whatEachDoes(), 'operation'));
    }

    public function testScaffoldingAnOperationAndAnEntityAreAdmittedEachByItsOwnAct(): void
    {
        self::assertTrue($this->call('sandbox:admit', ['operation' => 'make', 'what' => 'operation'], signed: true)['ok']);

        $list = AppliedTrials::forRoot($this->root);
        self::assertTrue($list->admits('make what=operation'));
        self::assertFalse($list->admits('make what=entity'), 'the house knows how to apply it; whether it does is this house\'s to say');
        self::assertTrue($this->call('sandbox:admit', ['operation' => 'make', 'what' => 'entity'], signed: true)['ok']);
        self::assertTrue(AppliedTrials::forRoot($this->root)->admits('make what=entity'));
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('notOneOfTheSix')]
    public function testOnlyTheSixCanBeAdmitted(array $input): void
    {
        $result = $this->call('sandbox:admit', $input, signed: true);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('cannot be admitted', $result['error']);
        self::assertStringContainsString('plugins.register, entity:seed, make what=page, make what=plugin, make what=operation, make what=entity', $result['error'], 'it says which can');
        self::assertStringContainsString('nothing was written', $result['error']);
        self::assertFileDoesNotExist($this->root . '/' . AppliedTrials::PATH);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function notOneOfTheSix(): iterable
    {
        yield 'a screen a visitor sees' => [['operation' => 'screen:declare']];
        yield 'authoring' => [['operation' => 'implement']];
        yield 'an edit' => [['operation' => 'edit']];
        yield 'a component' => [['operation' => 'component:define']];
        yield 'make of a test' => [['operation' => 'make', 'what' => 'test']];
        yield 'make of a controller' => [['operation' => 'make', 'what' => 'controller']];
        yield 'make of a crud' => [['operation' => 'make', 'what' => 'crud']];
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
