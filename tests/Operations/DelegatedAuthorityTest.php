<?php

/**
 * This file is part of Milpa App Runtime.
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\RecipeOperations;
use Milpa\AppRuntime\Operations\SequenceOperations;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/** A driver must retain current authority when it opens a fresh door for a step. */
final class DelegatedAuthorityTest extends TestCase
{
    public function testSequenceAndRecipeRetainTheCallerAndRefuseBeforeAsking(): void
    {
        foreach (['sequence', 'recipe'] as $driver) {
            $root = sys_get_temp_dir() . '/milpa-delegated-authority-' . bin2hex(random_bytes(5));
            mkdir($root . '/config', 0755, true);
            mkdir($root . '/recipes');
            $steps = [['op' => 'lab:read', 'args' => []]];
            file_put_contents($root . '/config/sequences.php', '<?php return ' . var_export(['probe' => $steps], true) . ';');
            file_put_contents($root . '/recipes/probe.json', json_encode(['work' => $steps]));
            $seen = [];
            $operation = new Operation(
                name: 'lab:read',
                effects: EffectProfile::readOnly(),
                description: 'Read under an exact scope',
                handler: static function (array $input, mixed $invocation = null, ?ToolContext $authority = null) use (&$seen): array {
                    $seen[] = $authority;
                    return ['ok' => true];
                },
                inputSchema: ['type' => 'object'],
                scopes: ['lab:read'],
            );
            $container = new DIContainer();
            $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
            foreach (['root' => $root, 'commands' => [$operation], 'container' => $container] as $name => $value) {
                (new \ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
            }
            $container->registerService(Kernel::class, $kernel);
            $provider = $driver === 'sequence' ? new SequenceOperations($container) : new RecipeOperations($container);
            $handler = $provider->operations()[0]->handler;
            $denied = $handler([$driver => 'probe'], null, new ToolContext(principal: 'finite', scopes: ['agent:run', 'recipe:apply']));
            self::assertFalse($denied['ok']);
            self::assertStringContainsString('lab:read', json_encode($denied));
            self::assertSame([], $seen, 'the missing scope cannot become the local wildcard');
            $events = (string) file_get_contents($root . '/var/agent-sessions.jsonl');
            self::assertStringNotContainsString('session.question_asked', $events);
            $allowed = new ToolContext(principal: 'finite', scopes: ['agent:run', 'recipe:apply', 'lab:read']);
            $positive = $handler([$driver => 'probe'], null, $allowed);
            self::assertTrue($positive['ok'], json_encode($positive));
            self::assertCount(1, $seen);
            self::assertSame($allowed->principal, $seen[0]->principal);
            self::assertSame($allowed->scopes, $seen[0]->scopes);
        }
    }
}
