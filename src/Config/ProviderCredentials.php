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

namespace Milpa\AppRuntime\Config;

/**
 * The credentials this house hands a model provider, and the one place that reads them
 * (greenhouse decisions/0589).
 *
 * `provider:declare` wrote `agent.apiKey` into the secret overlay and no request read it: a declared endpoint was
 * sent `MILPA_AGENT_API_KEY`, and only that. And {@see SecretRedaction} knew only the overlay. So the key that worked
 * was one the house did not protect, and the key it protected did not work — measured on published 0.211.1
 * (evidence/1130): the overlay's canary was never sent, and the environment's reached the model in 5 of 8 requests
 * once a test printed it.
 *
 * Two readers of one variable are two answers to «which key does this house send», so the variables are named here
 * and nowhere else — a test holds the rest of `src/` to that. Whoever sends a credential asks this class for it;
 * whoever must keep credentials out of what a resident reads asks it for all of them.
 *
 * THE DECLARED KEY WINS OVER THE ENVIRONMENT, as a declared endpoint wins over `MILPA_AGENT_BASE_URL`
 * ({@see AgentEndpoint::baseUrl()}): one rule for the endpoint and for its key. The environment is still accepted
 * when nothing is declared — a container, the Desktop and a lab arm hand the key that way.
 *
 * A key is read from the SECRET OVERLAY, never from the committed configuration: a value written in
 * `config/app.php` is one the house could not keep, and it is not sent.
 */
final class ProviderCredentials
{
    /** Where `provider:declare` keeps the key of a declared endpoint. */
    public const DECLARED_KEY = 'agent.apiKey';

    /** The variable a declared endpoint's key is read from when none is declared. */
    public const ENDPOINT_KEY = 'MILPA_AGENT_API_KEY';

    /** `user:password` for an endpoint behind basic auth. */
    public const BASIC_AUTH = 'MILPA_AGENT_BASIC_AUTH';

    /** Every variable this house reads a provider credential from. */
    public const VARIABLES = [self::ENDPOINT_KEY, 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', self::BASIC_AUTH];

    /** Static-only: readings of the overlay and of the environment. */
    private function __construct()
    {
    }

    /**
     * The key a declared endpoint is sent and where it came from — `declared` or `environment` — or null when the
     * house holds none.
     *
     * @param string|null $root the house root, or null when it is not known: then only the environment is read
     *
     * @return array{0: string, 1: 'declared'|'environment'}|null
     */
    public static function endpointKey(?string $root): ?array
    {
        $declared = $root === null ? [] : SecretOverlay::sobre([], $root);
        foreach (explode('.', self::DECLARED_KEY) as $segment) {
            $declared = \is_array($declared) ? ($declared[$segment] ?? null) : null;
        }
        if (\is_string($declared) && $declared !== '') {
            return [$declared, 'declared'];
        }
        $exported = self::read(self::ENDPOINT_KEY);

        return $exported === null ? null : [$exported, 'environment'];
    }

    /** The key of a provider this house talks to by its own name — `anthropic`, `openai` — or null. */
    public static function providerKey(string $provider): ?string
    {
        return self::read(strtoupper($provider) . '_API_KEY');
    }

    /** The `user:password` of an endpoint behind basic auth, or null when none is set or it is not a pair. */
    public static function basicAuth(): ?string
    {
        $pair = self::read(self::BASIC_AUTH);

        return $pair !== null && str_contains($pair, ':') ? $pair : null;
    }

    /**
     * Every credential the environment holds for a provider, as it could appear in a text: each value, and the
     * password of a basic-auth pair by itself.
     *
     * @return list<string>
     */
    public static function ofTheEnvironment(): array
    {
        $values = [];
        foreach (self::VARIABLES as $variable) {
            $value = self::read($variable);
            if ($value === null) {
                continue;
            }
            $values[] = $value;
            if ($variable === self::BASIC_AUTH && str_contains($value, ':')) {
                $values[] = substr($value, strpos($value, ':') + 1);
            }
        }

        return $values;
    }

    private static function read(string $variable): ?string
    {
        $value = \in_array($variable, self::VARIABLES, true) ? getenv($variable) : false;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
