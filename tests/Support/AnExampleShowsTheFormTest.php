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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Config\AgentKeys;
use Milpa\AppRuntime\Config\ProviderCredentials;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Command\Effect\Reversibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 AN EXAMPLE IS NOT THE EXAM (greenhouse decisions/0594 §5, question 7).
 *
 * What this package says — a contract, a refusal, a line of the system prompt — is read by every session
 * before it knows what it will be asked to build. Where an example was a real name (an entity, a route, a
 * test, a file of a plugin) the package had chosen a domain, and whoever was later asked for that very domain
 * had been handed part of the answer. So an example shows the FORM of the value: a placeholder where a name
 * goes (`<Entity>`, `/<path>`, `src/Plugins/<Plugin>/<Plugin>.php`), or a name this package answers for.
 *
 * This is a GUARD, not a unit test: it reads every string of `src/`, because the next example is written
 * somewhere this file has not heard of. And it is stated positively. It holds no list of names to avoid:
 * such a list, written in a package, would be the very thing it guards against.
 */
final class AnExampleShowsTheFormTest extends TestCase
{
    /** The words a form carries around its placeholders: the layout of a house, and a test's suffix. */
    private const GRAMMAR = ['src', 'Plugins', 'php', 'Test'];

    /**
     * The examples that are a name, one by one, each with what it is. A name enters here because this
     * package answers for it — never because it reads well.
     */
    private const OWN = [
        'plugins_register' => 'a tool of the house, as a session calls it',
        'plugins_lock' => 'a tool of the house, as a session calls it',
        'plan' => 'a tool this package declares',
        'write a plan before you start' => 'an obligation about order, said of that tool',
        'security reviewer of plugins' => 'a role, said with the house\'s own word',
        'governed-discovery' => 'a skill this package ships',
        'agent.apiKey' => 'the config path this package reads its provider key from',
        'agent.instructions' => 'a config key this package declares',
        'agent-runs' => 'a capability this package knows by that id',
        'reversibility' => 'an axis of the effect envelope',
        'compensatable' => 'a level of that axis',
        'application/json' => 'a media type',
        'PT1H' => 'an ISO-8601 duration',
        '[::1]' => 'the loopback address',
        'use 200, not 250' => 'a sentence about two numbers',
    ];

    public function testWhatFollowsAnExampleInWhatThisPackageSaysIsAForm(): void
    {
        $shown = [];
        foreach (self::strings() as $where => $text) {
            foreach (self::shown($text) as $value) {
                $shown[] = $value;
                self::assertTrue(
                    self::isAForm($value) || isset(self::OWN[$value]),
                    "{$where} shows «{$value}»: an example shows the form of the value — <Entity>, /<path>, <Name>Test — or a name this package answers for",
                );
            }
        }

        self::assertGreaterThan(20, \count($shown), 'the control: this package does show examples');
        self::assertContains('/<path>', $shown);
        self::assertContains('<Entity>', $shown);
        self::assertSame([], array_values(array_diff(array_keys(self::OWN), $shown)), 'a name nothing shows any more leaves the list');
    }

    /** The names shown as they are exist where this package says they do. */
    public function testTheNamesItShowsAreItsOwn(): void
    {
        self::assertDirectoryExists(\dirname(__DIR__, 2) . '/resources/skills/governed-discovery');
        self::assertSame('agent.apiKey', ProviderCredentials::DECLARED_KEY);
        self::assertTrue(AgentKeys::conocida('agent.instructions'));
        self::assertContains('agent-runs', Capabilities::knownIds());
        self::assertSame('compensatable', Reversibility::Compensatable->value);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function values(): iterable
    {
        yield 'a placeholder' => ['<Entity>', true];
        yield 'a path of placeholders' => ['/<path>', true];
        yield 'a path with a value a visitor sends' => ['/<path>/1', true];
        yield 'a file of a plugin, by its form' => ['src/Plugins/<Plugin>/<Plugin>.php', true];
        yield 'a test, by its form' => ['<Name>Test', true];
        yield 'an entity by a name' => ['Thing', false];
        yield 'a route by a name' => ['/things', false];
        yield 'a test by a name' => ['ThingTest', false];
        yield 'a file of a plugin by a name' => ['src/Plugins/Thing/Thing.php', false];
        yield 'a name beside a placeholder' => ['/things/<id>', false];
    }

    /** The control of the rule itself: it tells a form from a name, in both directions. */
    #[DataProvider('values')]
    public function testTheRuleTellsAFormFromAName(string $value, bool $form): void
    {
        self::assertSame($form, self::isAForm($value));
    }

    public function testItFindsTheValueAnExampleShows(): void
    {
        self::assertSame(['Thing'], self::shown('The entity by its short name, e.g. Thing — or Shop/Thing when two plugins have one'));
        self::assertSame(['/things'], self::shown('mount the screen at this literal GET path, e.g. /things: no parameters'));
        self::assertSame(['ThingTest'], self::shown('when its last run is green, e.g. `ThingTest`'));
        self::assertSame(['a thing for our team'], self::shown('Put what this app is for — for example «a thing for our team». Nothing was written.'));
        self::assertSame(['label', '…'], self::shown('e.g. [{"label": "…"}]. A row already declared is not added twice'));
        self::assertSame([], self::shown('declare rows of an entity (e.g. one row that is public and one that is not) and the house seeds them'), 'words that describe are not a value');
        self::assertSame([], self::shown('nothing is shown here'));
    }

    /**
     * Every string literal of `src/`, keyed by where it is. Comments and docblocks are not among them: a
     * session is not handed those.
     *
     * @return iterable<string, string>
     */
    private static function strings(): iterable
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $n => $token) {
                if (!\is_array($token) || !\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $text = $token[0] === \T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];

                yield substr($file->getPathname(), \strlen($src) + 1) . ":{$token[2]}#{$n}" => str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $text);
            }
        }
    }

    /**
     * The values a text shows as examples: what follows "e.g.", "for example" or "such as", up to the end of
     * its clause. A value sits between « », backticks or double quotes, or is a bare token shaped like code (a
     * path, a Name, a name:type, a key=value) or standing alone. Words that describe — "one row that is
     * public" — are not a value.
     *
     * @return list<string>
     */
    private static function shown(string $text): array
    {
        $values = [];
        preg_match_all('/(?:\be\.g\.,?|\bfor example,?|\bsuch as)\s+(.*?)(?=(?<![A-Za-z0-9\/])[.;](?:\s|$)| — |\)(?:[\s.,;:]|$)|\n|$)/isu', $text, $clauses);

        foreach ($clauses[1] as $clause) {
            $clause = trim($clause);
            if (preg_match_all('/«([^»]*)»|`([^`]*)`|"([^"]*)"/u', $clause, $quoted, \PREG_SET_ORDER) > 0) {
                foreach ($quoted as $q) {
                    $values[] = implode('', \array_slice($q, 1));
                }

                continue;
            }

            $first = rtrim((string) (preg_split('/[\s,]+/', $clause)[0] ?? ''), '.;:)');
            $alone = preg_match('/\s/', rtrim($clause, '.;:)')) !== 1;
            if ($first !== '' && ($alone || preg_match('/^[\/<{\[]|^[A-Z]|[:=_\\\\]/', $first) === 1)) {
                $values[] = $first;
            }
        }

        return $values;
    }

    /** Whether a shown value is a form: placeholders, and around them only the grammar of a house and digits. */
    private static function isAForm(string $value): bool
    {
        $rest = preg_replace('/<[^<>\s]+>|…/u', '', $value) ?? $value;
        if ($rest === $value) {
            return false;
        }

        foreach (preg_split('/[^A-Za-z]+/', $rest, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (!\in_array($word, self::GRAMMAR, true)) {
                return false;
            }
        }

        return true;
    }
}
