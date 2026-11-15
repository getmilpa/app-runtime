<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class APromotionAtEveryDoorNeedsAPersonTest extends TestCase
{
    private const KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function callers(): iterable
    {
        foreach (['shell', 'token', 'wildcard-token', 'seat', 'wildcard-seat', 'revoked-seat', 'broken-ledger', 'other-call', 'other-operation'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('callers')]
    public function testADirectCallerWithoutAPersonsSignatureCannotPromote(string $kind): void
    {
        [$root, $container] = $this->house();
        $scopes = str_contains($kind, 'wildcard') ? ['*'] : ['plugins.Blog:write'];
        $context = new ToolContext(principal: $kind, channel: $kind === 'shell' ? 'cli' : 'mcp', scopes: $scopes);
        if (str_contains($kind, 'seat') || in_array($kind, ['broken-ledger', 'other-call', 'other-operation'], true)) {
            $this->signed($container, $kind === 'other-call' ? ['workspace' => 'other'] : ['workspace' => 'w1'], $kind === 'other-operation' ? 'sandbox:discard' : 'sandbox:promote');
            $context = new ToolContext(principal: 'key:' . self::KEY, channel: 'cli', scopes: $scopes);
        }
        if (str_contains($kind, 'seat')) {
            $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
            $ledger->record(new IdentityEnrolled(self::KEY, $scopes, 'key:person'));
            if ($kind === 'revoked-seat') {
                $ledger->revoke(self::KEY, 'key:person');
            }
        }
        if ($kind === 'broken-ledger') {
            mkdir($root . '/storage/identity', 0o700, true);
            file_put_contents($root . '/storage/identity/enrollments.json', '{broken');
        }
        $answer = $this->promote($root, $container, $context);
        self::assertFalse($answer['ok'], json_encode($answer) ?: '');
        self::assertFileDoesNotExist($root . '/src/Plugins/Blog/Marker.php');
        self::assertNotNull(TrialWorkspace::open($root, 'w1'), 'refusing preserves the trial');
    }

    public function testTheControlAPersonSigningThisCallPromotesTheSameBytes(): void
    {
        [$root, $container] = $this->house();
        $this->signed($container, ['workspace' => 'w1']);
        $answer = $this->promote($root, $container, new ToolContext(principal: 'key:' . self::KEY, channel: 'cli', scopes: ['*']));
        self::assertTrue($answer['ok'], json_encode($answer) ?: '');
        self::assertSame("<?php // approved marker\n", file_get_contents($root . '/src/Plugins/Blog/Marker.php'));
    }

    /** @return array{string, DIContainer} */
    private function house(): array
    {
        $root = sys_get_temp_dir() . '/milpa-promotion-person-' . bin2hex(random_bytes(4));
        mkdir($root . '/src/Plugins/Blog', 0o700, true);
        file_put_contents($root . '/src/A.php', "<?php // original\n");
        $this->roots[] = $root;
        $workspace = TrialWorkspace::materialize($root, 'w1', dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        file_put_contents($workspace->copy . '/src/Plugins/Blog/Marker.php', "<?php // approved marker\n");

        return [$root, new DIContainer()];
    }

    /** @param array<string, mixed> $arguments */
    private function signed(DIContainer $container, array $arguments, string $operation = 'sandbox:promote'): void
    {
        $container->registerService(GrantedAuthorization::class, new GrantedAuthorization(
            new OperationAuthorization($operation, $arguments, 'lab', '2026-11-15T00:00:00Z', 'lab-nonce'),
            new VerifiedSigner(self::KEY),
            'verified in the test fixture',
            'fixture signature',
        ));
    }

    /** @return array<string, mixed> */
    private function promote(string $root, DIContainer $container, ToolContext $context): array
    {
        foreach ((new TrialOperations($container, root: $root, bootProbe: null, routes: null))->operations() as $operation) {
            if ($operation->name === 'sandbox:promote') {
                return ($operation->handler)(['workspace' => 'w1'], null, $context);
            }
        }
        self::fail('sandbox:promote is missing');
    }
}
