<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\EventStore\Event;

/**
 * What the human asked a session for, and whether it names a subject (greenhouse decisions/0496, 0522).
 *
 * The standing ask is the session's current goal (`session.started`, `session.goal_changed`) and every turn the
 * human wrote into it. Naming is compared mechanically, never with a model: an identifier is named when the ask
 * carries it as a whole identifier, ignoring case (0496). The frontier asks it before it offers a grant; the
 * house asks it before an observation of itself may close a session.
 *
 * ── A ROUTE IS NAMED BY ITS PATH, OR BY ITS FIRST SEGMENT ───────────────────────────────────────
 *
 * `/blog` is named by an ask that writes `/blog` as a whole path (not `/blog` inside `/blogs` or `/blog/x`), or by
 * one that names `blog` as a whole identifier — «build the blog» names the blog's route the way it names the Blog
 * plugin. The root `/` has no segment, so only an ask that writes `/` by itself names it. Measured (greenhouse
 * evidence/1050): an empty plugin was registered, the house observed `GET /` → 200, and that observation closed a
 * session whose goal was «GET /blog».
 *
 * ── A ROUTE THE ASK WRITES IS THE ONLY ONE THAT CLOSES (greenhouse decisions/0555) ──────────────
 *
 * An explicit route is the method and a literal path, as HTTP writes them: `GET /blog`. When the ask writes one, the
 * words around it stop naming routes: only the paths it writes name a subject. Measured (evidence/1088): the goal said
 * «serves GET /blog … listing only published posts», `make crud` landed `/posts`, the word «posts» named it, and the
 * house verified the session on `/posts` while `/blog` answered 404. Only `GET` is read: it is the one method the
 * house requests to see a route served ({@see HouseRouteObserver}). An ask that writes several is closed by any of
 * them; one that writes none names its subjects as before.
 */
final class StandingAsk
{
    /** `GET` as HTTP writes it, blanks, and a literal path: what {@see explicitRoutes()} reads. */
    private const EXPLICIT_ROUTE = '~(?<![A-Za-z0-9_])GET[ \t]+(/[A-Za-z0-9_{}%:\~./-]*)~u';

    private function __construct(private readonly string $text)
    {
    }

    /**
     * The standing ask of a session, read from its own stream.
     *
     * @param list<Event> $events
     */
    public static function in(array $events): self
    {
        $ask = [self::goalIn($events)];
        foreach ($events as $event) {
            if ($event->type === 'session.turn' && ($event->payload['role'] ?? null) === 'user' && \is_string($event->payload['content'] ?? null)) {
                $ask[] = $event->payload['content'];
            }
        }

        return new self(implode("\n", $ask));
    }

    /**
     * A standing ask already read as text — the goal and the human's turns, as {@see text()} returns them.
     */
    public static function ofText(string $text): self
    {
        return new self($text);
    }

    /**
     * The session's current goal: the last one it started with or changed to, or '' when it declares none.
     *
     * @param list<Event> $events
     */
    public static function goalIn(array $events): string
    {
        $goal = '';
        foreach ($events as $event) {
            if (\in_array($event->type, ['session.started', 'session.goal_changed'], true) && \is_string($event->payload['goal'] ?? null)) {
                $goal = $event->payload['goal'];
            }
        }

        return $goal;
    }

    /**
     * The ask as one text: the goal, then the human's turns, one per line.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * Whether the ask names this identifier as a whole identifier, ignoring case.
     *
     * Stricter than the `target_not_named` gate (decisions/0009), which only relaxes a question and so accepts a
     * substring: a grant is authority over one identifier, so «a plugin named Blog» names `Blog` and `blog`, but
     * neither `BlogPlugin` nor `log`. Ignoring case has a named cost: a common word of the goal names itself, so
     * that sentence also names `Plugin`.
     */
    public function namesIdentifier(string $identifier): bool
    {
        return $identifier !== ''
            && preg_match('/(?<![A-Za-z0-9_])' . preg_quote($identifier, '/') . '(?![A-Za-z0-9_])/iu', $this->text) === 1;
    }

    /**
     * The routes the ask writes explicitly — `GET` and a literal path — as it writes them, each once, in order.
     *
     * The path ends where its characters do, and a full stop that closes the sentence is not part of it: «serves
     * GET /blog.» writes `GET /blog`. A query string is not read. Lowercase «get /blog» is prose, not a route.
     *
     * @return list<string>
     */
    public function explicitRoutes(): array
    {
        preg_match_all(self::EXPLICIT_ROUTE, $this->text, $matches);
        $routes = [];
        foreach ($matches[1] as $path) {
            $path = rtrim($path, '.');
            $routes[self::pathKey($path)] ??= 'GET ' . ($path === '' ? '/' : $path);
        }

        return array_values($routes);
    }

    /**
     * Whether the ask names the subject the house observed: a route (`/blog`) or a screen (`tasks`).
     *
     * When the ask writes explicit routes ({@see explicitRoutes()}), a subject is named only by being the path of one
     * of them — compared without the slashes at its ends, as the house records a route both ways (`blog`, `/blog`), and
     * ignoring case. Otherwise a route is named by its whole path, or by its first static segment named as an
     * identifier; a screen by its name as an identifier. Nothing else is read: not the prose around it, not the answer.
     */
    public function namesSubject(string $subject): bool
    {
        $explicit = $this->explicitRoutes();
        if ($explicit !== []) {
            foreach ($explicit as $route) {
                if (self::pathKey(substr($route, 4)) === self::pathKey($subject)) {
                    return true;
                }
            }

            return false;
        }
        if (!str_starts_with($subject, '/')) {
            return $this->namesIdentifier($subject);
        }
        $path = rtrim($subject, '/');
        $path = $path === '' ? '/' : $path;
        if (preg_match('~(?<![A-Za-z0-9_./-])' . preg_quote($path, '~') . '(?![A-Za-z0-9_/-]|\.[A-Za-z0-9])~iu', $this->text) === 1) {
            return true;
        }
        $first = explode('/', ltrim($path, '/'))[0];

        return $first !== '' && !str_contains($first, '{') && $this->namesIdentifier($first);
    }

    /**
     * A path as two writings of it are compared: without the slashes at its ends, in lowercase.
     */
    private static function pathKey(string $path): string
    {
        return strtolower(trim($path, '/'));
    }
}
