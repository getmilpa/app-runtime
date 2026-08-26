<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\RecipeOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the `recipe:apply` operation's DECLARATION — its effect ceiling, its intent
 * contract, its surfaces and its schema — the part of {@see RecipeOperations} that needs no booted
 * app. The handler itself is exercised from a running app (milpa/framework) and the cattle harness;
 * here the contract a reader of `coa list` depends on is pinned.
 *
 * @internal
 */
final class RecipeOperationsTest extends TestCase
{
    private function provider(): RecipeOperations
    {
        // operations() never touches the container — only the handler does — so a stub is enough.
        return new RecipeOperations($this->createStub(DIContainerInterface::class));
    }

    private function recipeApply(): Operation
    {
        $ops = $this->provider()->operations();
        self::assertCount(1, $ops);
        self::assertSame('recipe:apply', $ops[0]->name);

        return $ops[0];
    }

    public function testItDeclaresTheCeilingOfWhatARecipeCanOriginate(): void
    {
        $ceiling = $this->recipeApply()->effectCeiling();

        self::assertSame(Mutation::Persistent, $ceiling->mutation);
        self::assertSame(Externality::ThirdParty, $ceiling->externality);
        self::assertSame(Reversibility::ManualRecovery, $ceiling->reversibility);
        self::assertSame(Authority::Privileged, $ceiling->authority);
        self::assertSame(Subject::Executable, $ceiling->subject);
    }

    public function testTheHumanMustNameTheRecipeAndItReachesEverySurfaceButHttp(): void
    {
        $op = $this->recipeApply();

        self::assertTrue($op->mutating);
        self::assertSame('recipe', $op->namedTarget);
        self::assertSame(['cli', 'tui', 'mcp'], $op->surfaces);
        self::assertNotContains('http', (array) $op->surfaces);
    }

    public function testItsSchemaRequiresARecipeName(): void
    {
        $schema = $this->recipeApply()->inputSchema;

        self::assertIsArray($schema);
        self::assertSame(['recipe'], $schema['required']);
        self::assertArrayHasKey('recipe', $schema['properties']);
        self::assertSame('string', $schema['properties']['recipe']['type']);
    }
}
