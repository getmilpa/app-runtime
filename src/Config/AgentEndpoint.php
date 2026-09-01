<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Config;

use Milpa\Runtime\Config;

/**
 * Where this app's agent talks to, resolved ONCE and asked by everyone who needs to know.
 *
 * The precedence was written twice and the two copies disagreed, which is the only way this class of
 * defect ever shows up. `AgentOperations` read the governed configuration first and fell back to the
 * environment; the chat banner read the environment and nothing else. So a human who configured
 * their agent through the governed path opened the first screen and was told they had configured
 * nothing — while the request on the wire went where they had asked (greenhouse evidence/0165,
 * measured with a capture proxy).
 *
 * A LIE ON THE FIRST SCREEN IS WORSE THAN A WRONG VALUE: it teaches that the path this framework
 * built does not work.
 *
 * greenhouse evidence/0141 settled the shape of the answer — a convention is CALLED, not copied —
 * and evidence/0159 applied it to configuration keys. This applies it to the endpoint. The banner is
 * not patched into agreement: it loses the right to resolve, because patching it would have left
 * THREE copies of the precedence instead of two.
 *
 * THE ENVIRONMENT STILL WORKS, and that is not a leftover. Whoever exports MILPA_AGENT_BASE_URL and
 * never writes a config file is exercising the path this framework documents; making the governed
 * source win must not take theirs away, only settle who wins when both speak.
 */
final class AgentEndpoint
{
    /**
     * The endpoint this app talks to, or null when it talks to a provider's default.
     *
     * Declared configuration wins over the environment: it passed through consent and left an acta,
     * and a consented choice that a stray variable overrides in silence is the same hole
     * greenhouse decisions/0027 named.
     */
    public static function baseUrl(?Config $config): ?string
    {
        $declarado = $config?->get('agent.baseUrl');
        if (\is_string($declarado) && $declarado !== '') {
            return $declarado;
        }

        $entorno = getenv('MILPA_AGENT_BASE_URL');

        return \is_string($entorno) && $entorno !== '' ? $entorno : null;
    }

    /**
     * The model's declared context window in tokens, from the same precedence — or `null` when
     * nobody declared one.
     *
     * It resolves HERE and not in the compaction bridge because it is a property of the endpoint
     * the same way the model's name is: whoever swaps the model swaps the context that comes with
     * it, and a second resolver would be the two-copies defect this class exists to close. The
     * value hands the `Compactor` a whole-window budget — measured need in greenhouse
     * evidence/0443, where a 32,768-token model was re-entered at 35.6k because only the turn tail
     * had a budget and nothing bounded the system side.
     *
     * A value that is not a positive whole number resolves as undeclared rather than guessed: a
     * budget of `0` or `-1` would not bound a window, it would poison every derived share.
     */
    public static function contextTokens(?Config $config): ?int
    {
        $declarado = $config?->get('agent.contextTokens');
        if (\is_int($declarado) && $declarado > 0) {
            return $declarado;
        }
        if (\is_string($declarado) && ctype_digit($declarado) && (int) $declarado > 0) {
            return (int) $declarado;
        }

        $entorno = getenv('MILPA_AGENT_CONTEXT_TOKENS');
        if (\is_string($entorno) && ctype_digit($entorno) && (int) $entorno > 0) {
            return (int) $entorno;
        }

        return null;
    }

    /** The model name, from the same precedence, or null when nobody named one. */
    public static function model(?Config $config): ?string
    {
        $declarado = $config?->get('agent.model');
        if (\is_string($declarado) && $declarado !== '') {
            return $declarado;
        }

        $entorno = getenv('MILPA_AGENT_MODEL');

        return \is_string($entorno) && $entorno !== '' ? $entorno : null;
    }

    /**
     * One line a human can trust: which provider, which model, resolved the way the call resolves.
     *
     * It says `provider · model` rather than the model alone because `qwen3-coder:30b` on a local
     * endpoint and the same name against a remote proxy are not the same thing to whoever pays for
     * the run.
     */
    public static function describe(?Config $config): string
    {
        $modelo = self::model($config);

        if (self::baseUrl($config) !== null) {
            return 'local · ' . ($modelo ?? 'qwen3-coder:30b');
        }
        if (getenv('ANTHROPIC_API_KEY')) {
            return 'anthropic · ' . ($modelo ?? 'claude-sonnet-4-5');
        }
        if (getenv('OPENAI_API_KEY')) {
            return 'openai · ' . ($modelo ?? 'gpt-4o');
        }

        return 'sin credencial — el agente no va a poder correr';
    }
}
