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

namespace Milpa\AppRuntime\Tests\Web;

/**
 * A synthetic authenticator: a real ES256 key that produces the bytes a browser would — a `none`
 * attestation for registration, a signed assertion for authentication — with no device in the loop.
 *
 * The same marshalling the passkey controller tests carry inline, gathered so the gate's end-to-end
 * test (greenhouse decisions/0206) can register, enroll and sign in through the real HTTP door.
 */
final class SyntheticPasskey
{
    /** A fresh P-256 key — the one algorithm milpa/auth verifies (ES256, alg -7). */
    public static function key(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('openssl could not mint a P-256 key');
        }

        return $key;
    }

    /** The public key in PEM, the way the credential store keeps it. */
    public static function pem(\OpenSSLAsymmetricKey $key): string
    {
        return (string) openssl_pkey_get_details($key)['key'];
    }

    /**
     * The registration body: a `webauthn.create` clientDataJSON over `$challenge` and a `none`
     * attestation carrying the raw credential id and the COSE public key.
     *
     * @return array{clientDataJSON: string, attestationObject: string} base64url fields
     */
    public static function attestation(\OpenSSLAsymmetricKey $key, string $rpId, string $challenge, string $rawCredentialId): array
    {
        $client = (string) json_encode([
            'type' => 'webauthn.create',
            'challenge' => self::b64u($challenge),
            'origin' => 'https://' . $rpId,
        ]);
        $d = openssl_pkey_get_details($key);
        // COSE EC2 coordinates are FIXED-WIDTH (RFC 8152: 32 bytes for P-256) and CoseKey refuses any
        // other length. OpenSSL hands the big-endian integer back WITHOUT leading zero bytes — 31 bytes
        // for 0.87% of keys (measured: 26 of 3000) — which made this helper's attestation fail
        // `passkey_rejected` in about one run in a hundred (greenhouse evidence/0521: the intermittent
        // PasskeyGateLoopTest failure was the instrument, not the door). A real authenticator pads.
        $x = str_pad($d['ec']['x'], 32, "\0", \STR_PAD_LEFT);
        $y = str_pad($d['ec']['y'], 32, "\0", \STR_PAD_LEFT);
        $cose = self::cborCoseMap([1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y]);
        $authData = hash('sha256', $rpId, true) . "\x41" . pack('N', 0)
            . str_repeat("\x00", 16) . pack('n', \strlen($rawCredentialId)) . $rawCredentialId . $cose;
        $att = self::cborHead(5, 3)
            . self::cborText('fmt') . self::cborText('none')
            . self::cborText('attStmt') . self::cborHead(5, 0)
            . self::cborText('authData') . self::cborBytes($authData);

        return ['clientDataJSON' => self::b64u($client), 'attestationObject' => self::b64u($att)];
    }

    /**
     * The authentication body: a `webauthn.get` clientDataJSON over `$challenge`, authenticator data
     * with user presence and a climbing counter, and the ES256 signature over both.
     *
     * @return array{credentialId: string, clientDataJSON: string, authenticatorData: string, signature: string}
     */
    public static function assertion(\OpenSSLAsymmetricKey $key, string $rpId, string $challenge, string $credentialId, int $counter = 7): array
    {
        $client = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::b64u($challenge),
            'origin' => 'https://' . $rpId,
        ]);
        $data = hash('sha256', $rpId, true) . "\x01" . pack('N', $counter);
        $sig = '';
        openssl_sign($data . hash('sha256', $client, true), $sig, $key, \OPENSSL_ALGO_SHA256);

        return [
            'credentialId' => $credentialId,
            'clientDataJSON' => self::b64u($client),
            'authenticatorData' => self::b64u($data),
            'signature' => self::b64u($sig),
        ];
    }

    /** base64url without padding — the WebAuthn wire encoding. */
    public static function b64u(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** The inverse of {@see b64u()}. */
    public static function unb64u(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    /** @param array<int, int|string> $map */
    private static function cborCoseMap(array $map): string
    {
        $out = self::cborHead(5, \count($map));
        foreach ($map as $k => $v) {
            $out .= self::cborInt($k);
            $out .= \is_int($v) ? self::cborInt($v) : self::cborBytes($v);
        }

        return $out;
    }

    private static function cborInt(int $n): string
    {
        return $n >= 0 ? self::cborHead(0, $n) : self::cborHead(1, -1 - $n);
    }

    private static function cborBytes(string $sVal): string
    {
        return self::cborHead(2, \strlen($sVal)) . $sVal;
    }

    private static function cborText(string $sVal): string
    {
        return self::cborHead(3, \strlen($sVal)) . $sVal;
    }

    private static function cborHead(int $major, int $value): string
    {
        $mt = $major << 5;
        if ($value < 24) {
            return \chr($mt | $value);
        }
        if ($value < 256) {
            return \chr($mt | 24) . \chr($value);
        }

        return \chr($mt | 25) . pack('n', $value);
    }
}
