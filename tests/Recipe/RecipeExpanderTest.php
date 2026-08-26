<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Recipe;

use Milpa\AppRuntime\Agent\SequenceStep;
use Milpa\AppRuntime\Recipe\Recipe;
use Milpa\AppRuntime\Recipe\RecipeExpander;
use PHPUnit\Framework\TestCase;

final class RecipeExpanderTest extends TestCase
{
    /**
     * (a) + (b): one RecipeExpander instance, fed two differently-shaped declarations (a blog and
     * a notes app), produces the analogous step list for each with NO code change in between —
     * the load-bearing proof that the expander is domain-blind.
     */
    public function testTheSameExpanderProducesAnalogousStepsForBlogAndNotesDeclarations(): void
    {
        $expander = new RecipeExpander();

        $blog = Recipe::fromArray('blog', [
            'foundation' => ['domain' => 'publishing', 'objective' => 'a blog'],
            'requires' => ['capabilities' => ['milpa/data', 'milpa/devtools']],
            'work' => [
                [
                    'op' => 'make:crud',
                    'args' => ['plugin' => 'Blog', 'name' => 'Post', 'fields' => 'title:string,body:text,published:bool'],
                ],
            ],
        ]);

        $blogSteps = $expander->expand($blog, 'unfounded', null, []);

        self::assertSame(
            ['foundation:found', 'capabilities:enable', 'capabilities:enable', 'make'],
            \array_map(static fn (SequenceStep $s): string => $s->operation, $blogSteps),
        );
        self::assertSame(['domain' => 'publishing', 'objective' => 'a blog'], $blogSteps[0]->arguments);
        self::assertSame(['capability' => 'milpa/data'], $blogSteps[1]->arguments);
        self::assertSame(['capability' => 'milpa/devtools'], $blogSteps[2]->arguments);
        self::assertSame(
            ['what' => 'crud', 'plugin' => 'Blog', 'name' => 'Post', 'fields' => 'title:string,body:text,published:bool'],
            $blogSteps[3]->arguments,
        );

        // Same $expander, a completely different domain shape — a notes app, not a blog. No
        // entity name, no domain literal, lives in RecipeExpander for this to work.
        $notes = Recipe::fromArray('notes', [
            'foundation' => ['domain' => 'knowledge', 'objective' => 'take notes'],
            'requires' => ['capabilities' => ['milpa/data']],
            'work' => [
                [
                    'op' => 'make:crud',
                    'args' => ['plugin' => 'Notes', 'name' => 'Note', 'fields' => 'title:string,body:text'],
                ],
            ],
        ]);

        $notesSteps = $expander->expand($notes, 'unfounded', null, []);

        self::assertSame(
            ['foundation:found', 'capabilities:enable', 'make'],
            \array_map(static fn (SequenceStep $s): string => $s->operation, $notesSteps),
        );
        self::assertSame(['domain' => 'knowledge', 'objective' => 'take notes'], $notesSteps[0]->arguments);
        self::assertSame(['capability' => 'milpa/data'], $notesSteps[1]->arguments);
        self::assertSame(
            ['what' => 'crud', 'plugin' => 'Notes', 'name' => 'Note', 'fields' => 'title:string,body:text'],
            $notesSteps[2]->arguments,
        );
    }

    /**
     * (c): a capability already installed is never re-enabled.
     */
    public function testAlreadyInstalledCapabilityIsSkipped(): void
    {
        $recipe = Recipe::fromArray('blog', [
            'foundation' => ['domain' => 'publishing', 'objective' => 'a blog'],
            'requires' => ['capabilities' => ['milpa/data', 'milpa/devtools']],
            'work' => [],
        ]);

        $steps = (new RecipeExpander())->expand($recipe, 'unfounded', null, ['milpa/data']);

        $operations = \array_map(static fn (SequenceStep $s): array => [$s->operation, $s->arguments], $steps);

        self::assertContains(['capabilities:enable', ['capability' => 'milpa/devtools']], $operations);
        self::assertNotContains(['capabilities:enable', ['capability' => 'milpa/data']], $operations);
    }

    /**
     * (d) part 1: founded, same domain → compatible, and expand() builds no foundation step (the
     * app is already founded — there is nothing left to found).
     */
    public function testFrontierIsCompatibleWhenFoundedDomainMatchesAndFoundationStepIsOmitted(): void
    {
        $recipe = Recipe::fromArray('blog', [
            'foundation' => ['domain' => 'publishing', 'objective' => 'a blog'],
            'requires' => ['capabilities' => []],
            'work' => [],
        ]);
        $expander = new RecipeExpander();

        self::assertSame('compatible', $expander->foundationFrontier($recipe, 'founded', 'publishing'));

        $steps = $expander->expand($recipe, 'founded', 'publishing', []);
        self::assertSame([], \array_map(static fn (SequenceStep $s): string => $s->operation, $steps));
    }

    /**
     * (d) part 2: founded, a different domain → incompatible, and expand() refuses to proceed.
     */
    public function testFrontierIsIncompatibleWhenFoundedDomainDiffersAndExpandThrows(): void
    {
        $recipe = Recipe::fromArray('blog', [
            'foundation' => ['domain' => 'publishing', 'objective' => 'a blog'],
            'requires' => ['capabilities' => []],
            'work' => [],
        ]);
        $expander = new RecipeExpander();

        self::assertSame('incompatible', $expander->foundationFrontier($recipe, 'founded', 'inventory'));

        $this->expectException(\RuntimeException::class);
        $expander->expand($recipe, 'founded', 'inventory', []);
    }

    /**
     * (d) part 3: an unrecognised verdict (e.g. `invalid`) is indeterminate, and expand() refuses
     * to proceed rather than guess.
     */
    public function testFrontierIsIndeterminateForAnUnrecognisedVerdictAndExpandThrows(): void
    {
        $recipe = Recipe::fromArray('blog', [
            'foundation' => ['domain' => 'publishing', 'objective' => 'a blog'],
            'requires' => ['capabilities' => []],
            'work' => [],
        ]);
        $expander = new RecipeExpander();

        self::assertSame('indeterminate', $expander->foundationFrontier($recipe, 'invalid', null));

        $this->expectException(\RuntimeException::class);
        $expander->expand($recipe, 'invalid', null, []);
    }

    /**
     * A founded app with a recipe that declares no foundation at all is compatible — there is no
     * domain to clash with.
     */
    public function testFrontierIsCompatibleWhenFoundedAndRecipeDeclaresNoFoundation(): void
    {
        $recipe = Recipe::fromArray('devtools-only', [
            'requires' => ['capabilities' => ['milpa/devtools']],
            'work' => [],
        ]);

        self::assertSame(
            'compatible',
            (new RecipeExpander())->foundationFrontier($recipe, 'founded', 'publishing'),
        );
    }

    /**
     * Even proceeding unfounded, a recipe that declares no foundation gets no foundation step —
     * there is nothing declared to found.
     */
    public function testExpandOmitsFoundationStepWhenRecipeDeclaresNone(): void
    {
        $recipe = Recipe::fromArray('devtools-only', [
            'requires' => ['capabilities' => []],
            'work' => [['op' => 'make:controller', 'args' => ['name' => 'Health']]],
        ]);

        $steps = (new RecipeExpander())->expand($recipe, 'unfounded', null, []);

        self::assertSame(['make'], \array_map(static fn (SequenceStep $s): string => $s->operation, $steps));
    }

    /**
     * The ONLY special-casing allowed is the generic `make:<kind>` -> `make` normalization —
     * anything else passes through untouched, with no args key defaulting to an empty array.
     */
    public function testNonMakeOperationPassesThroughUnchanged(): void
    {
        $recipe = Recipe::fromArray('blog', [
            'requires' => ['capabilities' => []],
            'work' => [
                ['op' => 'cache:clear', 'args' => ['tag' => 'blog']],
                ['op' => 'queue:flush'],
            ],
        ]);

        $steps = (new RecipeExpander())->expand($recipe, 'unfounded', null, []);

        self::assertSame('cache:clear', $steps[0]->operation);
        self::assertSame(['tag' => 'blog'], $steps[0]->arguments);
        self::assertSame('queue:flush', $steps[1]->operation);
        self::assertSame([], $steps[1]->arguments);
    }

    /**
     * `Recipe::fromArray` defaults every optional path when the declaration omits it entirely.
     */
    public function testFromArrayDefaultsMissingKeysToEmptyOrNull(): void
    {
        $recipe = Recipe::fromArray('bare', []);

        self::assertSame('bare', $recipe->name);
        self::assertNull($recipe->foundation);
        self::assertSame([], $recipe->capabilities);
        self::assertSame([], $recipe->work);
    }

    /**
     * `Recipe::fromArray` preserves the optional `boundaries` foundation field untouched, and
     * `expand()` carries it straight through into the `foundation:found` step's arguments.
     */
    public function testFromArrayPreservesOptionalBoundaries(): void
    {
        $recipe = Recipe::fromArray('blog', [
            'foundation' => ['domain' => 'publishing', 'objective' => 'a blog', 'boundaries' => ['no billing']],
            'requires' => ['capabilities' => []],
            'work' => [],
        ]);

        self::assertSame(['no billing'], $recipe->foundation['boundaries'] ?? null);

        $steps = (new RecipeExpander())->expand($recipe, 'unfounded', null, []);

        self::assertSame(
            ['domain' => 'publishing', 'objective' => 'a blog', 'boundaries' => ['no billing']],
            $steps[0]->arguments,
        );
    }
}
