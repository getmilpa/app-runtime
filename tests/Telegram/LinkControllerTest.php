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

use Milpa\AppRuntime\Telegram\Catalog;
use Milpa\AppRuntime\Telegram\LinkController;
use Milpa\AppRuntime\Web\PasskeySessionResolver;
use Milpa\Auth\ActorType;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\SessionRecord;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The link's door asks for the passkey before it says anything, and opens once (greenhouse decisions/0572).
 */
final class LinkControllerTest extends TestCase
{
    use House;

    private InMemorySessionStore $passkeySessions;

    public function testWithoutAPasskeySessionEveryTokenGoesToTheSignInAndLearnsNothing(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep();
        $minted = $this->bot->token(1);
        $never = str_repeat('B', 43);

        $a = $this->door()->open(new ServerRequest('GET', '/telegram/open/' . $minted));
        $b = $this->door()->open(new ServerRequest('GET', '/telegram/open/' . $never));

        self::assertSame(302, $a->getStatusCode());
        self::assertSame('/webauthn/signin?next=' . rawurlencode('/telegram/open/' . $minted), $a->getHeaderLine('Location'));
        self::assertSame(302, $b->getStatusCode(), 'a token nobody minted is answered the same way');
        self::assertSame('no-store', $a->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $a->getHeaderLine('Referrer-Policy'));
        self::assertNotNull($this->notifier()->open($minted, self::PASSKEY), 'and an anonymous visit did not spend the link');
    }

    public function testSignedInThePersonIsSentToThePanelOnce(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep();
        $request = $this->signedIn(self::PASSKEY, '/telegram/open/' . $this->bot->token(1));

        $first = $this->door()->open($request);
        $again = $this->door()->open($request);

        self::assertSame(302, $first->getStatusCode());
        self::assertSame('/milpa/admin?session=' . self::SESSION, $first->getHeaderLine('Location'));
        self::assertSame(410, $again->getStatusCode(), 'a replay opens nothing');
        self::assertStringContainsString('This link is not valid any more.', (string) $again->getBody());
    }

    public function testAnotherPersonsPasskeyGetsTheSameAnswerAsADeadLink(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep();

        $stranger = $this->door()->open($this->signedIn(self::STRANGER, '/telegram/open/' . $this->bot->token(1)));
        $dead = $this->door()->open($this->signedIn(self::STRANGER, '/telegram/open/' . str_repeat('C', 43)));

        self::assertSame(410, $stranger->getStatusCode());
        self::assertSame((string) $dead->getBody(), (string) $stranger->getBody());
        self::assertStringNotContainsString(self::SESSION, (string) $stranger->getBody());
    }

    public function testWhatIsNotShapedLikeATokenIsRefusedBeforeAnythingIsAsked(): void
    {
        foreach (['short', str_repeat('A', 44), str_repeat('A', 42) . '.', '..'] as $bad) {
            self::assertSame(410, $this->door()->open(new ServerRequest('GET', '/telegram/open/' . $bad))->getStatusCode(), $bad);
        }
    }

    public function testARevokedPasskeyIsSentBackToTheSignIn(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep();
        $request = $this->signedIn(self::PASSKEY, '/telegram/open/' . $this->bot->token(1));
        $this->enrollments->revoke('cred-of-the-human', self::HUMAN);

        self::assertSame(302, $this->door()->open($request)->getStatusCode());
        self::assertStringStartsWith('/webauthn/signin?next=', $this->door()->open($request)->getHeaderLine('Location'));
    }

    public function testASessionThatIsNotAPasskeysIsNotThisDoorsToHonour(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep();

        $answer = $this->door()->open($this->signedIn('token:ci', '/telegram/open/' . $this->bot->token(1)));

        self::assertSame(302, $answer->getStatusCode());
        self::assertStringStartsWith('/webauthn/signin', $answer->getHeaderLine('Location'));
    }

    public function testTheDeadLinkPageSpeaksTheHousesLocale(): void
    {
        $door = new LinkController($this->notifier(), $this->resolver(), new Catalog('es'));

        self::assertStringContainsString('Este enlace ya no es válido.', (string) $door->open(new ServerRequest('GET', '/telegram/open/x'))->getBody());
    }

    private function door(): LinkController
    {
        return new LinkController($this->notifier(), $this->resolver(), new Catalog());
    }

    private function resolver(): PasskeySessionResolver
    {
        $this->passkeySessions ??= new InMemorySessionStore();

        return new PasskeySessionResolver($this->passkeySessions, $this->enrollments, 'milpa_session');
    }

    private function signedIn(string $principal, string $path): ServerRequest
    {
        $this->resolver();
        $now = new \DateTimeImmutable();
        $this->passkeySessions->write(new SessionRecord('sid-' . md5($principal), $principal, ActorType::User, $now, $now->modify('+1 hour'), ['agent:answer']));

        return (new ServerRequest('GET', $path))->withCookieParams(['milpa_session' => 'sid-' . md5($principal)]);
    }
}
