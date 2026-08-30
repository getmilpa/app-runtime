<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\Capabilities;
use PHPUnit\Framework\TestCase;

final class RegisterOperationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-reg-' . getmypid() . '-' . uniqid();
        @mkdir($this->root . '/config', 0o777, true);
        file_put_contents($this->root . '/config/operations.php', "<?php\ndeclare(strict_types=1);\nreturn [\n    Milpa\\AppRuntime\\Operations\\FoundationOperations::class,\n];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/config/operations.php');
        @rmdir($this->root . '/config');
        @rmdir($this->root);
    }

    public function testItDeclaresAProviderAndIsIdempotent(): void
    {
        $first = Capabilities::registerOperations($this->root, ['Milpa\\WebSearch\\WebSearchOperations']);
        self::assertSame(['Milpa\\WebSearch\\WebSearchOperations'], $first);

        $again = Capabilities::registerOperations($this->root, ['Milpa\\WebSearch\\WebSearchOperations']);
        self::assertSame([], $again, 'a provider already declared is not written twice');

        $src = (string) file_get_contents($this->root . '/config/operations.php');
        self::assertSame(1, substr_count($src, 'WebSearchOperations'));

        $arr = require $this->root . '/config/operations.php';
        self::assertContains('Milpa\\WebSearch\\WebSearchOperations', array_map(static fn (string $c): string => ltrim($c, '\\'), $arr));
    }

    public function testNoConfigFileMeansNothingWritten(): void
    {
        self::assertSame([], Capabilities::registerOperations(sys_get_temp_dir() . '/milpa-nope-' . uniqid(), ['X\\Y']));
    }
}
