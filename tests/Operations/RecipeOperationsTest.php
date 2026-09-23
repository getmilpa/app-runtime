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

    private function byName(string $name): Operation
    {
        $ops = $this->provider()->operations();
        // BOTH DOORS, NAMED — and still counted, so a third one cannot arrive unnoticed: this file
        // pins what a reader of `coa list` depends on.
        self::assertSame(['recipe:apply', 'recipe:plan'], array_map(static fn (Operation $o): string => $o->name, $ops));

        foreach ($ops as $op) {
            if ($op->name === $name) {
                return $op;
            }
        }

        self::fail("no operation named {$name}");
    }

    private function recipeApply(): Operation
    {
        return $this->byName('recipe:apply');
    }

    /**
     * 🚨 THE PLAN IS A READ, SO ASKING WHAT A RECIPE WOULD DO COSTS NO CEREMONY.
     *
     * This is the regression guard for what cost Rod two YubiKey touches: each signature bought a
     * PREREQUISITE — «unknown capability», then «no session store» — because the only way to ask
     * this sequence anything was to authorize it first. Rule S2 demands consent when subject is
     * Executable or above AND authority is Privileged or above; a read at None/Read cannot trip it,
     * for any arguments, which is why the contrast below is the whole fix (greenhouse decisions/0457).
     *
     * A descent was tried first and refused itself: `Descent` exists for «a rehearsal is not the
     * act», but `holds()` wants a SIGNED certificate bound to the handler digest for every axis under
     * `authority`, because a badly declared descent EXEMPTS instead of punishing. A read asks for no
     * exemption.
     */
    public function testThePlanIsAReadSoAskingWhatItWouldDoCostsNoCeremony(): void
    {
        $plan = $this->byName('recipe:plan');
        $ceiling = $plan->ceilingForCall(['recipe' => 'blog']);

        self::assertSame(Mutation::None, $ceiling->mutation);
        self::assertSame(Authority::Read, $ceiling->authority);
        self::assertSame(Subject::None, $ceiling->subject);
        self::assertFalse($plan->mutating);
        self::assertSame(['cli', 'tui', 'mcp'], $plan->surfaces);

        // AND THE CONTRAST, which is what a reader needs to see: the same recipe, the same app, one
        // door that must be authorized and one that answers.
        $apply = $this->recipeApply()->ceilingForCall(['recipe' => 'blog']);
        self::assertSame(Authority::Privileged, $apply->authority);
        self::assertSame(Subject::Executable, $apply->subject);

        if (class_exists(\Milpa\Console\Consent::class)) {
            self::assertFalse(
                \Milpa\Console\Consent::demanded($plan, ['recipe' => 'blog']),
                'a plan that demands consent is a plan nobody can read for free',
            );
            self::assertTrue(\Milpa\Console\Consent::demanded($this->recipeApply(), ['recipe' => 'blog']));
        }
    }

    /** The plan names the recipe it reads, like its sibling. */
    public function testThePlanRequiresARecipeName(): void
    {
        $schema = $this->byName('recipe:plan')->inputSchema;

        self::assertIsArray($schema);
        self::assertSame(['recipe'], $schema['required']);
        self::assertSame('string', $schema['properties']['recipe']['type']);
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
