<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Console\Application;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The real Application dispatch must judge a presented token before entering CliRunner. */
final class ApplicationScopeTest extends TestCase
{
    private string $root;
    private string|false $previousToken;
    private const string TOKEN = 'test-scope-parity-token';

    protected function setUp(): void
    {
        $this->previousToken = getenv('MILPA_TOKEN');
        $this->root = sys_get_temp_dir() . '/milpa-cli-scope-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        $this->previousToken === false ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $this->previousToken);
        foreach (glob($this->root . '/config/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root . '/config');
        rmdir($this->root);
    }

    /** @return iterable<string, array{list<string>, ?string, bool}> */
    public static function identities(): iterable
    {
        yield 'sufficient' => [['probe:read'], self::TOKEN, true];
        yield 'insufficient' => [['posts:read'], self::TOKEN, false];
        yield 'absent' => [['posts:read'], null, true];
        yield 'invalid' => [['posts:read'], 'not-minted', true];
        yield 'empty retains 0311 default' => [[], self::TOKEN, true];
    }

    /** @param list<string> $scopes */
    #[DataProvider('identities')]
    public function testTheCliBoundsAClassifiedReadByThePresentedToken(array $scopes, ?string $token, bool $allowed): void
    {
        $executed = 0;
        $app = $this->application($scopes, new Operation(
            name: 'probe.read',
            description: 'Read the fixture',
            handler: function () use (&$executed): array {
                ++$executed;
                return ['read' => true];
            },
            effects: EffectProfile::readOnly(),
            scopes: ['probe:read'],
        ));
        $token === null ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);
        [$exit, $output] = $this->dispatch($app, ['probe:read', '--json']);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($allowed ? 0 : 1, $exit);
        self::assertSame($allowed ? 1 : 0, $executed);
        self::assertSame($allowed, $result['ok']);
        if (!$allowed) {
            self::assertSame("Missing required scope for tool 'probe_read'. Need one of: probe:read — context has: posts:read.", $result['error']);
        }
    }

    /** An out-of-scope mutation must report scope, before the CLI asks for a signature. */
    public function testAMutationCannotAskForSignatureUntilScopeAdmitsIt(): void
    {
        $executed = 0;
        $op = new Operation(name: 'probe.write', description: 'Write', handler: function () use (&$executed): int {
            return ++$executed;
        }, mutating: true, requiresConfirmation: true, scopes: ['probe:write']);
        putenv('MILPA_TOKEN=' . self::TOKEN);
        [$exit, $output] = $this->dispatch($this->application(['posts:read'], $op), ['probe:write']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Missing required scope', $output);
        self::assertStringNotContainsString('Re-run with --sign', $output);
        self::assertSame(0, $executed);

        [$exit, $output] = $this->dispatch($this->application(['probe:write'], $op), ['probe:write']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Re-run with --sign', $output);
        self::assertSame(0, $executed, 'scope cannot replace call-bound consent');
    }

    /** @param list<string> $scopes */
    private function application(array $scopes, Operation $op): Application
    {
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => \\' . self::class . '::container(' . var_export($scopes, true) . '), "plugins" => []];');
        $app = new Application($this->root);
        // The fixture supplies the operation; dispatch and boot are the shipped implementations.
        (new \ReflectionProperty($app, 'operations'))->setValue($app, [$op]);

        return $app;
    }

    /** @param list<string> $scopes */
    public static function container(array $scopes): DIContainer
    {
        $container = new DIContainer();
        $repository = new InMemoryRepository(ApiToken::class);
        $repository->save(new ApiToken(hash: TokenVerifier::hash(self::TOKEN), actor: 'developer', scopes: $scopes, createdAt: '2026-09-11T00:00:00+00:00'));
        $container->registerService(TokenVerifier::class . '.repository', $repository);

        return $container;
    }

    /** @param list<string> $arguments @return array{int, string} */
    private function dispatch(Application $app, array $arguments): array
    {
        ob_start();
        try {
            $exit = $app->run(['coa', ...$arguments]);

            return [$exit, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}
