<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THREE WAYS `house:start` ANSWERED SOMETHING IT DID NOT KNOW — the residue of its own audit.
 *
 * `decisions/0305` closed the defects that made the "Start here" sign undeserved and left three
 * named and counted. All three are the same question: whether the answer tells the truth about what
 * it established (greenhouse decisions/0307).
 */
#[CoversClass(AgentOperations::class)]
final class TheAnswerSaysWhatItCouldNotKnowTest extends TestCase
{
    /**
     * 🚨 «I COULD NOT READ YOUR VENDOR TREE» IS NOT «YOU HAVE NOTHING».
     *
     * `Capabilities::declaredBy()` answers `[]` for both, and says so in its own comment. Read as a
     * fact, that made the first screen ship `ok: true` with a blank `installed:` and then propose
     * installing capabilities — the two states have opposite fixes, and a reader could not tell them
     * apart.
     *
     * Measured by mutation on cattle: manifest moved aside → exit 0, `installed:` blank, `next` still
     * offering four `capabilities:enable`. After: `ok: no` and the sentence naming `composer install`,
     * for the missing file AND for one that does not parse.
     */
    public function testAnUnreadableVendorManifestIsARefusalAndNotAnEmptyList(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-no-manifest-' . bin2hex(random_bytes(4));
        mkdir($dir . '/vendor/composer', 0o777, true);

        // The seam the docblock documents: the vendor root whose manifest says what is switched on.
        // A booted house, because the KERNEL guard comes first — it needs `$root` before the manifest
        // can be resolved at all. My first version of this test passed a container with no kernel and
        // measured the kernel's refusal instead, which is the same mistake as reading the element you
        // edited.
        $answer = $this->booted()->houseStart($dir . '/vendor');

        self::assertFalse($answer['ok'], 'an empty read is not a state to report as fact');
        self::assertIsString($answer['error']);
        self::assertStringContainsString('composer install', $answer['error'], 'the refusal names the fix');
        self::assertStringContainsString('installed.json', $answer['error']);
        self::assertArrayNotHasKey('capabilities', $answer, 'and it does not half-answer');

        // A manifest that exists and does not parse is the same fact, not a different one.
        file_put_contents($dir . '/vendor/composer/installed.json', '{ "packages": [ ');
        $again = $this->booted()->houseStart($dir . '/vendor');
        self::assertFalse($again['ok']);

        @unlink($dir . '/vendor/composer/installed.json');
        @rmdir($dir . '/vendor/composer');
        @rmdir($dir . '/vendor');
        @rmdir($dir);
    }

    /**
     * 🚨 THE GUARD RESOLVES THE MANIFEST THE WAY THE READER RESOLVES IT.
     *
     * The first version read `$root . '/vendor'` while `declaredBy()` does
     * `$vendor ??= raizDeLaApp() . '/vendor'` — Composer's running autoloader, because in a path
     * monorepo this package has its OWN `vendor/` and walking up finds the wrong root. It refused a
     * house whose manifest was perfectly readable, and the suite caught it on the fixture immediately:
     * **a guard that checks a different file than the one that was read certifies nothing and refuses
     * the innocent.**
     */
    public function testTheGuardReadsTheSameFileTheAnswerIsBuiltFrom(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Operations/AgentOperations.php');

        self::assertStringContainsString(
            "\$manifest = (\$vendor ?? Capabilities::raizDeLaApp() . '/vendor') . '/composer/installed.json';",
            $source,
            'the guard must resolve the vendor root exactly as declaredBy() does',
        );
        self::assertStringNotContainsString("\$manifest = (\$vendor ?? \$root . '/vendor')", $source);
    }

    /**
     * 🚨 THE VERDICT KEEPS THE SENTENCE THE AUTHORITY COMPUTED WITH IT.
     *
     * The handler called `Foundation::answer()` and consumed `verdict()`: one word. `answer()`'s own
     * docblock says why it exists — «an unfounded app answering an empty object would leave the caller
     * exactly where it started. The teaching IS the answer» — and `house:context` passes the payload
     * whole. This door, the one the welcome page signs, was the only one where it disappeared.
     *
     * One place chooses, because the three payload names are the authority's vocabulary and not this
     * operation's. Measured on cattle, all four verdicts:
     *
     *   unfounded     → «Call `foundation:found` with the domain the HUMAN named…»
     *   invalid       → «…contradicts its contract — repair the document; re-founding is refused»
     *   indeterminate → «cannot be adjudicated honestly (possibly a newer schema)…»
     *   founded       → the key is ABSENT
     */
    public function testEveryVerdictThatHasSomethingLeftToSaySaysIt(): void
    {
        $sentence = new \ReflectionMethod(AgentOperations::class, 'foundationSentence');
        $sentence->setAccessible(true);

        self::assertSame(
            'Call `foundation:found` …',
            $sentence->invoke(null, ['verdict' => 'unfounded', 'teach' => ['how' => 'Call `foundation:found` …']]),
        );
        self::assertSame('repair the document', $sentence->invoke(null, ['verdict' => 'invalid', 'repair' => 'repair the document']));
        self::assertSame('cannot be adjudicated', $sentence->invoke(null, ['verdict' => 'indeterminate', 'hint' => 'cannot be adjudicated']));

        // 🚨 ABSENT, not empty — the rule this screen learned from nine blank `unlocks`.
        self::assertNull($sentence->invoke(null, ['verdict' => 'founded', 'founded' => true]));
        self::assertNull($sentence->invoke(null, ['verdict' => 'unfounded', 'teach' => ['how' => '   ']]), 'whitespace is not a sentence');
    }

    /**
     * 🚨 ONE VOCABULARY: both lists name a capability the same two ways.
     *
     * `installed` named it by `id` while `available` named it by `package`, so after running the very
     * step this screen recommends — `capabilities:enable milpa/admin` — the reader looked for
     * `milpa/admin` in the answer and found a bare `admin` in a different list, with no way to confirm
     * the two are the same thing. `state()` carries both names on every entry.
     */
    public function testInstalledAndAvailableSpeakOneVocabulary(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Operations/AgentOperations.php');

        self::assertStringContainsString("'capability' => (string) \$c['id'],", $source);
        self::assertStringContainsString("'package' => (string) \$c['package'],", $source);
        // Two scalars per row, so the renderer still tables it — the property that halved this screen.
        self::assertStringNotContainsString("static fn (array \$c): string => (string) \$c['id']", $source);
    }

    /** A booted house, so the manifest guard is reached — the kernel guard runs before it. */
    private function booted(): AgentOperations
    {
        $container = new DIContainer();
        $container->registerService(Kernel::class, Kernel::boot([
            'root' => sys_get_temp_dir(),
            'container' => $container,
            'dispatcher' => new EventDispatcher(new NullLogger()),
            'toolRegistry' => new ToolRegistry(new NullLogger()),
            'plugins' => [],
            'config' => ['app' => ['name' => 'manifest-probe']],
        ]));

        return new AgentOperations($container);
    }
}
