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

namespace Milpa\AppRuntime\Tests\Recipe;

use Milpa\EventStore\InMemoryEventStore;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\GovernedExecutor;
use Milpa\AppRuntime\Recipe\Recipe;
use Milpa\AppRuntime\Recipe\RecipeDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A recipe that finishes says what its steps left for a human — or `applied: yes` is its last word
 * over work that is not done.
 *
 * Measured (greenhouse `evidence/0989`): a recipe founded a domain, installed two capabilities and
 * scaffolded a blog; the answer was five keys and the blog was invisible, because `make` had
 * reported «plugin not yet listed in config/plugins.php» inside its own result and this payload
 * dropped it. Eight signatures, and the verdict hid the one remaining step.
 *
 * @guards the completion payload relaying each step's guidance
 *
 * @fires  when a recipe runs to completion
 *
 * @refuses nothing — it is a projection, and it interprets no producer's result
 *
 * @subject-in milpa/app-runtime
 */
#[CoversClass(RecipeDriver::class)]
final class TheRecipeSaysWhatIsStillYoursTest extends TestCase
{
    public function testTheStepsGuidanceReachesWhoeverPaidForTheRun(): void
    {
        $result = $this->applyWith($this->executorAnswering([
            'demo:read' => ['ok' => true],
            'demo:mutate' => [
                'ok' => true,
                'guidance' => 'plugin not yet listed in config/plugins.php — add Blog::class to boot it',
            ],
            'demo:finish' => ['ok' => true, 'guidance' => '   '],
        ]));

        self::assertTrue($result['applied'], 'the run did finish');
        self::assertArrayHasKey('remaining', $result, 'and it says what is still yours');
        self::assertSame(
            [[
                'operation' => 'demo:mutate',
                'guidance' => 'plugin not yet listed in config/plugins.php — add Blog::class to boot it',
            ]],
            $result['remaining'],
            'verbatim, and only the step that left one — a blank guidance is not a fact',
        );
    }

    public function testACleanRunKeepsItsShortAnswer(): void
    {
        // THE CONTROL: without this, a driver that always appended the key would read as fixed
        // while teaching every caller to ignore an always-present field.
        $result = $this->applyWith($this->executorAnswering([
            'demo:read' => ['ok' => true],
            'demo:mutate' => ['ok' => true],
            'demo:finish' => ['ok' => true],
        ]));

        self::assertTrue($result['applied']);
        self::assertArrayNotHasKey('remaining', $result, 'nothing outstanding says nothing');
    }

    public function testAResultThatIsNotAnArrayOrCarriesNoStringIsSkippedNotCoerced(): void
    {
        // A step's result belongs to its producer and can be any shape. Guessing at one is how two
        // components end up disagreeing about what happened, so anything unexpected is skipped.
        $result = $this->applyWith(new class () implements GovernedExecutor {
            public function callTool(string $operation, array $arguments): mixed
            {
                return match ($operation) {
                    'demo:read' => 'a plain string',
                    'demo:mutate' => ['ok' => true, 'guidance' => ['not', 'a', 'string']],
                    default => ['ok' => true, 'guidance' => 42],
                };
            }
        });

        self::assertTrue($result['applied']);
        self::assertArrayNotHasKey('remaining', $result);
    }

    /** @param array<string, mixed> $answers */
    private function executorAnswering(array $answers): GovernedExecutor
    {
        return new class ($answers) implements GovernedExecutor {
            /** @param array<string, mixed> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function callTool(string $operation, array $arguments): mixed
            {
                return $this->answers[$operation] ?? ['ok' => true];
            }
        };
    }

    /** @return array<string, mixed> */
    private function applyWith(GovernedExecutor $executor): array
    {
        return (new RecipeDriver())->apply(
            Recipe::fromArray('demo', [
                'work' => [
                    ['op' => 'demo:read', 'args' => []],
                    ['op' => 'demo:mutate', 'args' => []],
                    ['op' => 'demo:finish', 'args' => []],
                ],
            ]),
            $executor,
            new SessionStore(new InMemoryEventStore()),
            'recipe:demo',
            static fn (): array => ['verdict' => 'founded', 'domain' => 'demo'],
            static fn (): array => [],
        );
    }
}
