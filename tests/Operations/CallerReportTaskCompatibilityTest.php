<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Composer\InstalledVersions;
use Milpa\AppRuntime\Agent\CallerReportTask;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallerReportTaskCompatibilityTest extends TestCase
{
    /** @return iterable<string,array{string,?string}> */
    public static function unavailable(): iterable
    {
        yield 'older-agent' => ['milpa/agent', '0.53.3.0'];
        yield 'development-alias' => ['milpa/agent', 'dev-main'];
        yield 'unknown-future-major' => ['milpa/agent', '1.0.0.0'];
        yield 'missing-agent' => ['milpa/agent', null];
        yield 'missing-gateway' => ['milpa/ai-gateway', null];
        yield 'older-gateway' => ['milpa/ai-gateway', '0.41.0.0'];
        yield 'missing-event-store' => ['milpa/event-store', null];
    }

    #[DataProvider('unavailable')]
    public function testUnavailableOptInRefusesBeforeAccessingTheContainer(string $package, ?string $version): void
    {
        $original = InstalledVersions::getRawData();
        $data = $original;
        if ($version === null) {
            unset($data['versions'][$package]);
        } else {
            $data['versions'][$package]['version'] = $version;
        }
        $ops = new AgentOperations(new DIContainer());
        $task = CallerReportTask::failedReadOnlyAttempt('session', 'task', 'probe');
        $loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
        foreach ($loaders as $loader) {
            $loader->unregister();
        }
        InstalledVersions::reload($data);
        try {
            $result = $ops->runReportTask([], $task);
            $binding = (new \ReflectionProperty(AgentOperations::class, 'callerReportTask'))->getValue($ops);
        } finally {
            InstalledVersions::reload($original);
            foreach ($loaders as $loader) {
                $loader->register();
            }
        }
        self::assertSame('report_task_dependency', $result['error']);
        self::assertSame($package, $result['package']);
        self::assertNull($binding);
    }
}
