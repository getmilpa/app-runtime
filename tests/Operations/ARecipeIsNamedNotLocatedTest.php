<?php

/**
 * This file is part of Milpa App Runtime — the governed runtime of a founded Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\ObservedExecutor;
use Milpa\AppRuntime\Operations\RecipeOperations;
use Milpa\Command\InvocationContext;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A recipe is NAMED, not located — and whoever runs it is READ, not invented.
 *
 * Two defects found by an adversarial review of the plan to give this operation an HTTP surface. Both
 * are true today, on every surface, and neither needed HTTP to be wrong.
 */
#[CoversClass(RecipeOperations::class)]
final class ARecipeIsNamedNotLocatedTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function notNames(): array
    {
        return [
            'a traversal' => ['../../../etc/passwd'],
            'a shallower traversal' => ['../secrets'],
            'an absolute path' => ['/etc/passwd'],
            'a subdirectory' => ['uploads/evil'],
            'the parent itself' => ['..'],
            'the current directory' => ['.'],
            'a hidden dotfile' => ['.env'],
        ];
    }

    /**
     * The step list of a recipe is EXECUTABLE, so choosing which file holds it is choosing code. This
     * used to be `$root . '/recipes/' . $name . '.json'` with no basename, no realpath and no test.
     */
    #[DataProvider('notNames')]
    public function testANameThatIsAPathIsRefusedBeforeTheFilesystemIsTouched(string $name): void
    {
        $answer = $this->apply(['recipe' => $name]);

        self::assertFalse($answer['ok'] ?? null, $name . ' was accepted as a recipe name');
        self::assertStringContainsString('is not a recipe name', (string) ($answer['error'] ?? ''));
        self::assertStringNotContainsString('no recipe at', (string) ($answer['error'] ?? ''), 'it never reached the filesystem');
    }

    /**
     * THE POSITIVE CONTROL. The guard must bite because the input is a path, not because every input is
     * refused: a real name gets past it and fails for the honest reason instead.
     */
    public function testARealNameGetsPastTheGuardAndFailsForTheHonestReason(): void
    {
        $answer = $this->apply(['recipe' => 'my-recipe.v2']);

        self::assertFalse($answer['ok'] ?? null);
        // It got PAST the name guard — what stops it now is the missing kernel, which is the honest
        // next question rather than a refusal of the name.
        self::assertStringNotContainsString('is not a recipe name', (string) ($answer['error'] ?? ''));
        self::assertStringContainsString('no kernel', (string) ($answer['error'] ?? ''));
    }

    /**
     * WHO RAN IT IS READ FROM THE INVOCATION, not from the environment.
     *
     * It used to build `Principal::fromTerminal(getenv('USER'), gethostname())` unconditionally, so over
     * any non-terminal surface the ledger would name the server process as the operator for every step.
     */
    public function testTheObservedExecutorFollowsTheInvocation(): void
    {
        $method = new \ReflectionMethod(RecipeOperations::class, 'observedExecutor');

        $web = $method->invoke(null, InvocationContext::web(actor: 'passkey:rod', authorizationId: 'recipe:apply'));
        self::assertSame('passkey:rod', $web->principal?->id);
        self::assertTrue($web->principal?->verified);
        self::assertSame('web', $web->source);

        $terminal = $method->invoke(null, null);
        self::assertSame(ObservedExecutor::TERMINAL, $terminal->source, 'a terminal IS observable, and stays so');
        self::assertNotNull($terminal->principal);

        // A surface that authenticated NOBODY says so, instead of naming the server process.
        $anonymous = $method->invoke(null, new InvocationContext(actor: null, channel: 'web'));
        self::assertNull($anonymous->principal, 'an honest gap beats false evidence with better typography');
        self::assertSame(ObservedExecutor::UNKNOWN, $anonymous->source);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function apply(array $input): array
    {
        $operations = (new RecipeOperations(new DIContainer()))->operations();
        $apply = null;
        foreach ($operations as $operation) {
            if ($operation->name === 'recipe:apply') {
                $apply = $operation;
            }
        }
        self::assertNotNull($apply);

        /** @var array<string, mixed> $answer */
        $answer = ($apply->handler)($input, null);

        return $answer;
    }
}
