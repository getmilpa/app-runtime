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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Attributes\PluginMetadata;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The catalogue must carry declarations, not deductions made from prose or defaults. */
final class AgentCatalogueTest extends TestCase
{
    public function testItExposesTheOperationDeclarationsThatGovernTheTool(): void
    {
        $tools = $this->toolsByName();

        self::assertFalse($tools['catalogue_read']['mutating']);
        self::assertFalse($tools['catalogue_read']['requiresConfirmation']);
        self::assertSame('none', $tools['catalogue_read']['effects']['mutation']);
        self::assertTrue($tools['catalogue_read']['effects']['fully_classified']);

        self::assertTrue($tools['catalogue_publish']['mutating']);
        self::assertTrue($tools['catalogue_publish']['requiresConfirmation']);
        self::assertSame('third_party', $tools['catalogue_publish']['effects']['externality']);
        self::assertSame('manual_recovery', $tools['catalogue_publish']['effects']['reversibility']);
        self::assertSame('write_as_user', $tools['catalogue_publish']['effects']['authority']);
        self::assertSame('data', $tools['catalogue_publish']['effects']['subject']);
    }

    /** An undeclared default is not evidence that the tool does not mutate. */
    public function testAnUndeclaredRegistryToolIsUnknownRatherThanNonMutating(): void
    {
        $tool = $this->toolsByName()['raw_tool'];

        self::assertArrayNotHasKey('mutating', $tool);
        self::assertArrayNotHasKey('requiresConfirmation', $tool);
        self::assertArrayNotHasKey('effects', $tool);
        self::assertSame(['mutating', 'requiresConfirmation', 'effects'], $tool['cannotSay']);
    }

    /** @return array<string, array<string, mixed>> */
    private function toolsByName(): array
    {
        $container = new DIContainer();
        $registry = new ToolRegistry(new NullLogger());
        $registry->register(
            'raw_tool',
            'A tool registered without effect declarations.',
            ['type' => 'object'],
            static fn (): array => ['ok' => true],
        );

        $kernel = Kernel::boot([
            'root' => \dirname(__DIR__, 2),
            'container' => $container,
            'toolRegistry' => $registry,
            'plugins' => [CatalogueDeclarationsPlugin::class],
        ]);
        $container->registerService(Kernel::class, $kernel);

        $result = (new AgentOperations($container))->catalogueFor([]);
        self::assertTrue($result['ok']);

        $byName = [];
        foreach ($result['tools'] as $tool) {
            $byName[$tool['name']] = $tool;
        }

        return $byName;
    }
}

#[PluginMetadata(
    version: '1.0.0',
    author: 'Milpa App Runtime Tests',
    site: 'https://example.test',
    name: 'CatalogueDeclarationsPlugin',
    type: 'Service',
)]
final class CatalogueDeclarationsPlugin implements PluginInterface, CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    /** @return list<Operation> */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'catalogue:read',
                description: 'Read catalogue state.',
                handler: static fn (): array => ['ok' => true],
                effects: EffectProfile::readOnly(),
            ),
            new Operation(
                name: 'catalogue:publish',
                description: 'Publish catalogue state.',
                handler: static fn (): array => ['ok' => true],
                mutating: true,
                requiresConfirmation: true,
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::ThirdParty,
                    reversibility: Reversibility::ManualRecovery,
                    authority: Authority::WriteAsUser,
                    subject: Subject::Data,
                ),
            ),
        ];
    }
}
