<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Telegram;

use Milpa\AppRuntime\Web\PasskeySessionResolver;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The door a card's link opens: `GET /telegram/open/<token>` (greenhouse decisions/0572).
 *
 * It decides nothing and shows nothing about the card. Without a passkey session it sends the browser to the
 * house's own sign-in, whatever the token is — so a link in the wrong hands learns nothing, not even whether it
 * was ever minted. With one, it asks {@see Notifier::open()} whether that card still waits for THAT principal;
 * a yes spends the link and sends the browser to the panel, where the decision is the panel's own ceremony over
 * what the house says. Anything else gets one answer: the link is not valid any more.
 */
final class LinkController
{
    /** What a minted token looks like: 32 bytes, base64url. */
    private const TOKEN = '/^[A-Za-z0-9_-]{43}$/';

    private const HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private readonly Notifier $notifier,
        private readonly PasskeySessionResolver $sessions,
        private readonly Catalog $catalog,
        private readonly string $signin = '/webauthn/signin',
    ) {
    }

    /** Send a signed-in person to the panel for the card their link names, once — or say the link is not valid. */
    public function open(ServerRequestInterface $request): ResponseInterface
    {
        $token = basename($request->getUri()->getPath());
        if (preg_match(self::TOKEN, $token) !== 1) {
            return $this->invalid();
        }

        $session = $this->sessions->resolve($request);
        $principal = $session->context->actor?->id;
        if (!$session->isLive() || $session->isForeign() || $principal === null) {
            return new Response(302, self::HEADERS + ['Location' => $this->signin . '?next=' . rawurlencode(Notifier::DOOR . $token)]);
        }

        $panel = $this->notifier->open($token, $principal);

        return $panel === null ? $this->invalid() : new Response(302, self::HEADERS + ['Location' => $panel]);
    }

    private function invalid(): ResponseInterface
    {
        $title = htmlspecialchars($this->catalog->tr('page.title'), \ENT_QUOTES);
        $text = htmlspecialchars($this->catalog->tr('page.invalid'), \ENT_QUOTES);

        return new Response(
            410,
            self::HEADERS + ['Content-Type' => 'text/html; charset=utf-8'],
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . "<title>{$title}</title><body style=\"font:16px/1.5 system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem\">"
            . "<p>{$text}</p></body>",
        );
    }
}
