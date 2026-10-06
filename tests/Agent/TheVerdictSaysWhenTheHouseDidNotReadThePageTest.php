<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\Evidence;
use Milpa\Agent\SessionStore;
use Milpa\Agent\Todo;
use Milpa\Agent\TodoStatus;
use Milpa\AppRuntime\Agent\ClosureVerdict;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * THE VERDICT SAYS WHEN THE HOUSE DID NOT READ THE PAGE (greenhouse decisions/0577 §3–4, slice BV-3).
 *
 * A page written by hand and a page the house read row by row both closed `verified: true`, and nothing in the
 * verdict told them apart. The closure does not change — a surface no declaration serves has no contract to be judged
 * by (decisions/0567, Rod's decision 7) — but the verdict now says what the house did: it read the page, it did not,
 * or it claims nothing because what answered was not a page.
 */
final class TheVerdictSaysWhenTheHouseDidNotReadThePageTest extends TestCase
{
    private const LISTS = ['entity' => 'Blog/Post', 'public' => 1, 'shown' => 1, 'withheld' => 1, 'leaked' => 0, 'withholding' => 'exercised'];
    private const BY_HAND = ['kind' => 'visual', 'screen' => null];
    private const BY_A_SCREEN = ['kind' => 'visual', 'screen' => 'blog'];

    public function testAPageNoDeclarationServesClosesAndIsSaidUnjudged(): void
    {
        $closure = $this->closureOver(['contentType' => 'text/html; charset=utf-8', 'surface' => self::BY_HAND]);

        self::assertTrue($closure['verified'], 'the closure does not change: ' . implode('; ', $closure['reasons']));
        self::assertSame('unjudged', $closure['derivedFrom']['observation']['content'] ?? null, 'and the verdict says the house did not read what it closed');
        self::assertSame(self::BY_HAND, $closure['derivedFrom']['observation']['surface'] ?? null);
    }

    public function testAPageTheHouseReadCarriesWhatItListedAndWhichScreenServedIt(): void
    {
        $closure = $this->closureOver(['contentType' => 'text/html; charset=utf-8', 'surface' => self::BY_A_SCREEN, 'content' => self::LISTS]);

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame(self::LISTS, $closure['derivedFrom']['observation']['content'] ?? null);
        self::assertSame(self::BY_A_SCREEN, $closure['derivedFrom']['observation']['surface'] ?? null);
    }

    public function testAScreenTheHouseCouldNotCompareIsUnjudgedToo(): void
    {
        $closure = $this->closureOver(['contentType' => 'text/html; charset=utf-8', 'surface' => self::BY_A_SCREEN]);

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('unjudged', $closure['derivedFrom']['observation']['content'] ?? null, 'a screen with rows of its own is a page the house served and did not read');
    }

    public function testWhatIsNotAPageIsNotCalledUnjudged(): void
    {
        $data = $this->closureOver(['contentType' => 'application/json']);
        self::assertTrue($data['verified'], implode('; ', $data['reasons']));
        self::assertSame(['subject' => '/blog', 'seq' => 2], $data['derivedFrom']['observation'], 'a JSON document is not a page the house failed to read');

        $before = $this->closureOver([]);
        self::assertSame(['subject' => '/blog', 'seq' => 2], $before['derivedFrom']['observation'], 'a receipt from before this slice says nothing, and neither does the verdict');
    }

    public function testWithTodosTheVerdictSaysItToo(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        $store->setTodo('s', new Todo('t1', 'Confirm /blog served', TodoStatus::Pending));
        $this->promote($store, ['contentType' => 'text/html', 'surface' => self::BY_HAND]);
        $store->completeTodo('s', 't1', Evidence::operationOk('e1', 'sandbox_promote'));
        $session = $store->load('s');
        self::assertNotNull($session);

        $closure = ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));

        self::assertTrue($closure['verified'], implode('; ', $closure['reasons']));
        self::assertSame('recorded_work_and_house_observation', $closure['scope']);
        self::assertSame('unjudged', $closure['derivedFrom']['observation']['content'] ?? null);
    }

    public function testASurfaceTheReceiptDoesNotCallVisualIsNotUnjudged(): void
    {
        $closure = $this->closureOver(['contentType' => 'text/plain', 'surface' => ['kind' => 'download', 'screen' => null]]);

        self::assertArrayNotHasKey('content', $closure['derivedFrom']['observation'], 'only a visual surface is a page to read');
    }

    /**
     * The house's verdict over a session whose one promotion observed the goal's route with this said about it.
     *
     * @param array<string, mixed> $said
     *
     * @return array{verified: bool, reasons: list<string>, scope: string, derivedFrom?: array<string, mixed>}
     */
    private function closureOver(array $said): array
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Build the blog: serve GET /blog as a page listing published posts.', AutonomyMode::Auto);
        $this->promote($store, $said);
        $session = $store->load('s');
        self::assertNotNull($session);

        return ClosureVerdict::derive($session, $store->facts('s'), $store->stream('s'));
    }

    /** @param array<string, mixed> $said */
    private function promote(SessionStore $store, array $said): void
    {
        $store->recordToolCall('s', 'sandbox_promote', ['workspace' => 'w1'], (string) json_encode([
            'ok' => true,
            'promoted' => ['src/Plugins/Blog/Controllers/BlogController.php'],
            'evidence' => ['predicate' => 'promoted', 'subject' => 'w1', 'environment' => ['kind' => 'house'], 'paths' => ['src/Plugins/Blog/Controllers/BlogController.php']],
            'observed' => [[
                'predicate' => 'served', 'route' => 'GET /blog', 'subject' => '/blog', 'status' => 200, 'environment' => ['kind' => 'house'],
                'servedAt' => '/blog', 'bytes' => 306, 'sha256' => hash('sha256', 'a page'),
            ] + $said],
        ]), mutating: true);
    }
}
