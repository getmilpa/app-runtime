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

namespace Milpa\AppRuntime\Web\Controllers;

use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyLogin;
use Milpa\Auth\WebAuthn\WebAuthnRegistrationVerifier;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTTP door of a passkey login: hand the browser a fresh challenge, then turn its assertion into a
 * session cookie — or refuse it (greenhouse decisions/0126).
 *
 * The controller is thin on purpose: the ceremony's safety lives in milpa/auth (single-use challenge,
 * signature, clone counter, recognition), and this only marshals bytes across HTTP. The credential's
 * three fields arrive base64url-encoded the way a browser sends them; the credential id stays the
 * base64url STRING it was registered as. On success the minted session id travels in an HttpOnly cookie
 * the framework's StartSession middleware reads back.
 */
final class PasskeyController
{
    public function __construct(
        private readonly PasskeyAuthenticator $authenticator,
        private readonly PasskeyLogin $login,
        private readonly ChallengeStore $challenges,
        private readonly WebAuthnRegistrationVerifier $registration,
        private readonly PasskeyCredentialStore $credentials,
        private readonly string $rpId,
        private readonly string $cookieName,
    ) {
    }

    /** Issue a fresh one-time challenge for a registration ceremony. */
    public function registerOptions(ServerRequestInterface $request): ResponseInterface
    {
        $challenge = $this->challenges->issue();

        return $this->json(200, [
            'rpId' => $this->rpId,
            'challenge' => self::base64UrlEncode($challenge),
        ]);
    }

    /**
     * Verify a registration attestation and, if it holds, remember the credential.
     *
     * The credential is now REGISTERED (the house holds its public key), not yet RECOGNIZED — granting it
     * scopes is identity:enroll's job, exactly as a fresh gpg key must be enrolled (greenhouse decisions/0125).
     */
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!\is_array($body)) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'The body is not a JSON object.']);
        }
        $clientData = self::base64UrlDecode(\is_string($body['clientDataJSON'] ?? null) ? $body['clientDataJSON'] : '');
        $attestation = self::base64UrlDecode(\is_string($body['attestationObject'] ?? null) ? $body['attestationObject'] : '');
        if ($clientData === null || $attestation === null) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'clientDataJSON and attestationObject are required.']);
        }

        // The challenge the client echoes must be one we issued and have not spent — consume it first.
        $decoded = json_decode($clientData, true);
        $challenge = self::base64UrlDecode(\is_array($decoded) && \is_string($decoded['challenge'] ?? null) ? $decoded['challenge'] : '');
        if ($challenge === null || !$this->challenges->consume($challenge)) {
            return $this->json(401, ['ok' => false, 'error' => 'passkey_rejected']);
        }

        $credential = $this->registration->verify($challenge, $this->rpId, $clientData, $attestation);
        if ($credential === null) {
            return $this->json(401, ['ok' => false, 'error' => 'passkey_rejected']);
        }
        $this->credentials->register($credential);

        return $this->json(201, [
            'ok' => true,
            'credentialId' => $credential->credentialId,
            'note' => 'registered — enroll this credential id to grant it scopes',
        ]);
    }

    /** Issue a fresh one-time challenge for an authentication ceremony. */
    public function options(ServerRequestInterface $request): ResponseInterface
    {
        $challenge = $this->authenticator->challenge();

        return $this->json(200, [
            'rpId' => $this->rpId,
            'challenge' => self::base64UrlEncode($challenge),
        ]);
    }

    /** Verify an assertion and, if the passkey is recognized, mint a session cookie. */
    public function authenticate(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!\is_array($body)) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'The body is not a JSON object.']);
        }

        $credentialId = \is_string($body['credentialId'] ?? null) ? $body['credentialId'] : '';
        $clientData = self::base64UrlDecode(\is_string($body['clientDataJSON'] ?? null) ? $body['clientDataJSON'] : '');
        $authData = self::base64UrlDecode(\is_string($body['authenticatorData'] ?? null) ? $body['authenticatorData'] : '');
        $signature = self::base64UrlDecode(\is_string($body['signature'] ?? null) ? $body['signature'] : '');
        if ($credentialId === '' || $clientData === null || $authData === null || $signature === null) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'credentialId, clientDataJSON, authenticatorData and signature are required.']);
        }

        $session = $this->login->login($this->rpId, $credentialId, $clientData, $authData, $signature);
        if ($session === null) {
            // Refused for any reason — replay, unknown or unrecognized credential, bad signature, clone.
            // The single message keeps the door from telling an attacker which check failed.
            return $this->json(401, ['ok' => false, 'error' => 'passkey_rejected']);
        }

        return $this->json(200, ['ok' => true, 'actor' => $session->actorId, 'scopes' => $session->scopes])
            ->withHeader('Set-Cookie', $this->cookieName . '=' . $session->id . '; HttpOnly; SameSite=Strict; Path=/');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            (string) json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
