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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The leg reads which calls last from the house's OWN catalogue (greenhouse decisions/0523): every closure reader of
 * the leg — the final answer, the epilogue's probe, the claim door — gets the one classifier this builds.
 *
 * @guards a declared persistent operation of the house lasts; the reading is built once per invocation
 *
 * @refuses an operation the house declares ephemeral (the dev server) as a lasting change; without a kernel there is
 *          no reading, and the readers keep the recorded flag
 *
 * @subject-in milpa/app-runtime
 */
final class TheLegReadsWhichCallsLastFromItsHouseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-ar-lasting-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents(
            $this->root . '/config/operations.php',
            "<?php\n\nreturn [\n"
                . '    \Milpa\AppRuntime\Operations\AgentOperations::class,' . "\n"
                . '    \Milpa\AppRuntime\Operations\CapabilityOperations::class,' . "\n"
                . "];\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheReadingComesFromTheHousesDeclarations(): void
    {
        $container = new DIContainer();
        $kernel = Kernel::boot([
            'root' => $this->root,
            'container' => $container,
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
        ]);
        $container->registerService(Kernel::class, $kernel);
        $operations = new AgentOperations($container);

        $lasting = $this->lastingCalls($operations);

        self::assertInstanceOf(\Closure::class, $lasting);
        self::assertFalse($lasting('serve', []), 'the dev server declares an ephemeral mutation');
        self::assertTrue($lasting('capabilities_enable', ['name' => 'admin']), 'enabling a capability lasts');
        self::assertFalse($lasting('capabilities_enable', ['name' => 'admin', 'dry_run' => true]), 'its declared dry run does not');
        self::assertNull($lasting('work_claim-verified', []), 'the session notebook is not the house catalogue');
        self::assertSame($lasting, $this->lastingCalls($operations), 'read once per invocation');
    }

    public function testWithoutAKernelThereIsNoReading(): void
    {
        self::assertNull($this->lastingCalls(new AgentOperations(new DIContainer())));
    }

    private function lastingCalls(AgentOperations $operations): ?\Closure
    {
        $method = new \ReflectionMethod($operations, 'lastingCalls');

        return $method->invoke($operations);
    }
}
