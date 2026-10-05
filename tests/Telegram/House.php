<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Telegram;

use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Telegram\Awaiting;
use Milpa\AppRuntime\Telegram\CardLedger;
use Milpa\AppRuntime\Telegram\Catalog;
use Milpa\AppRuntime\Telegram\Notifier;
use Milpa\EventStore\InMemoryEventStore;

/**
 * A house with one human key, the passkey it enrolled, a stranger's passkey, and a seat the human answers for.
 */
trait House
{
    private const HUMAN = 'key:C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const PASSKEY = 'passkey:cred-of-the-human';
    private const STRANGER = 'passkey:cred-of-a-stranger';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const SEAT_SCOPES = ['agent:run', 'plugins:read', 'plugins:write'];
    private const SESSION = 'blog-secret-session';
    private const GOAL = 'Build the blog at /srv/private/path: a plugin named Blog that serves GET /blog.';
    private const LINK = 'https://casa.example.ts.net';

    private string $root;
    private SessionStore $sessions;
    private FileEnrollmentStore $enrollments;
    private RecordingBot $bot;
    private InMemoryEventStore $events;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-telegram-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
        $this->enrollments = new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        $this->enrollments->record(new IdentityEnrolled('cred-of-the-human', ['identity:enroll', 'agent:answer'], self::HUMAN));
        $this->enrollments->record(new IdentityEnrolled('cred-of-a-stranger', ['identity:enroll', 'agent:answer'], 'key:0000000000000000000000000000000000000001'));
        $this->enrollments->record(new IdentityEnrolled(self::SEAT, self::SEAT_SCOPES, self::PASSKEY));
        $this->events = new InMemoryEventStore();
        $this->sessions = new SessionStore($this->events);
        $this->bot = new RecordingBot();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function notifier(string $for = self::PASSKEY, int $ttl = 900, bool $names = true, string $locale = 'en'): Notifier
    {
        return new Notifier(
            $this->bot,
            CardLedger::forRoot($this->root),
            Awaiting::forRoot($this->root, $this->sessions),
            $this->enrollments,
            new Catalog($locale),
            '4242',
            $for,
            self::LINK,
            '/milpa/admin',
            $ttl,
            $names,
        );
    }

    /** The seat opens its session and is refused `make plugin=Blog` for the scope it lacks. */
    private function aRefusal(string $session = self::SESSION): int
    {
        $this->sessions->start($session, self::GOAL, by: new Principal('key:' . self::SEAT, true));

        return $this->sessions->recordToolCall(
            $session,
            'make',
            ['what' => 'plugin', 'plugin' => 'Blog', 'name' => 'Blog', 'content' => 'SECRET FILE CONTENT'],
            "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.",
            false,
            true,
        );
    }

    /** A person grants the seat the scope its refusal names, as `identity:grant` writes it. */
    private function grant(string $by = self::PASSKEY): void
    {
        $this->enrollments->record(new IdentityEnrolled(self::SEAT, [...self::SEAT_SCOPES, 'plugins.Blog:write'], $by));
    }

    private function aQuestion(string $session, Principal $openedBy, string $operation = 'sandbox:promote'): void
    {
        $this->sessions->start($session, self::GOAL, by: $openedBy);
        $this->sessions->ask($session, new PendingQuestion(
            'perm:' . $operation,
            'May I promote the trial with /srv/private/path/config.php?',
            ['yes', 'no'],
            (string) json_encode(['operation' => $operation, 'arguments' => ['workspace' => 'trial-SECRET']]),
            null,
            'permission',
        ));
    }
}
