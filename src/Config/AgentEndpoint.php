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

use Milpa\AiGateway\ProviderReach;
use Milpa\AiGateway\ProviderWindow;
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
 *
 * ── AND SINCE decisions/0233, A THIRD SPEAKER: THE PROVIDER ──────────────────────────────────────
 *
 * The context window used to be the one value here that nothing could verify — a human assertion
 * that stayed unchallenged until the provider rejected the prompt mid-run. Now the house ASKS
 * ({@see measuredContextTokens()}) and keeps the SMALLER of the two ({@see contextWindow()}). The
 * precedence above still decides what a HUMAN declared; the measurement is not another declaration
 * competing in it, it is a ceiling over the winner.
 */
final class AgentEndpoint
{
    /**
     * What each provider answered when asked, keyed by base URL — the negative answer included.
     *
     * Asking is egress, and once per run is the contract (greenhouse decisions/0233, point 5). Two
     * callers already resolve this value on every turn — the orchestrator's construction and the
     * compaction bridge — so without this memo one turn would ask twice, and a provider that stayed
     * silent would be re-asked forever.
     *
     * @var array<string, null|int>
     */
    private static array $medido = [];

    /**
     * What the provider answered about its catalogue, memoised the same way — the silence included.
     *
     * @var array<string, null|array{reached: bool, models: list<string>, serves_declared: null|bool}>
     */
    private static array $alcance = [];

    /**
     * The network seam, or `null` for the shipped default.
     *
     * @var null|callable(string): ?string
     */
    private static $costura;

    /**
     * Point the provider question at something other than the network, and forget what was asked.
     *
     * A test that had to reach a live provider to exercise this precedence is a test that in
     * practice nobody runs — and one that silently DID reach it would turn every CI run into
     * egress against somebody's model host. Passing `null` restores the shipped fetcher and clears
     * the memo, which is what a `tearDown` wants.
     *
     * @param null|callable(string): ?string $fetch
     */
    public static function useProviderFetcher(?callable $fetch): void
    {
        self::$costura = $fetch;
        self::$medido = [];
        self::$alcance = [];
    }

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
     * The context window this app's agent runs under, or `null` when nothing produced one.
     *
     * It resolves HERE and not in the compaction bridge because it is a property of the endpoint
     * the same way the model's name is: whoever swaps the model swaps the context that comes with
     * it, and a second resolver would be the two-copies defect this class exists to close. The
     * value hands the `Compactor` a whole-window budget — measured need in greenhouse
     * evidence/0443, where a 32,768-token model was re-entered at 35.6k because only the turn tail
     * had a budget and nothing bounded the system side.
     *
     * Since greenhouse decisions/0233 the number has TWO sources, not one: what a human declared,
     * and what the provider says it allocated. **The smaller wins.** {@see contextWindow()} owns
     * that composition and explains why; this method is the same answer with the provenance
     * dropped, for the callers that only need the budget.
     */
    public static function contextTokens(?Config $config): ?int
    {
        return self::contextWindow($config)->tokens;
    }

    /**
     * The window that governs, with where it came from and both numbers that produced it.
     *
     * Callers that only need a budget keep using {@see contextTokens()}; a caller that has to tell
     * a human WHY their budget is what it is asks here. The composition rule and the reason for it
     * live in {@see ContextWindow::compose()} — one owner, so the two entry points can never drift
     * into disagreeing, which is the exact defect this class was created to end.
     */
    public static function contextWindow(?Config $config): ContextWindow
    {
        return ContextWindow::compose(
            self::declaredContextTokens($config),
            self::measuredContextTokens($config),
            self::baseUrl($config) !== null,
        );
    }

    /**
     * What a human declared: governed configuration first, then the environment.
     *
     * A value that is not a positive whole number resolves as undeclared rather than guessed: a
     * budget of `0` or `-1` would not bound a window, it would poison every derived share.
     */
    public static function declaredContextTokens(?Config $config): ?int
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

    /**
     * What the provider says it ALLOCATED, asked once per run — or `null` when it did not say.
     *
     * Three guards, each of which is a decision:
     *
     *  - **No base URL, no question.** An app that talks to a provider's default endpoint has not
     *    told this framework where its model lives, and inventing a host to interrogate would be
     *    egress nobody asked for. This is also the honest edge of the slice: the question is proven
     *    against one provider, and the acta says so rather than claiming it generalises.
     *  - **No reader, no question.** `milpa/ai-gateway` older than 0.23 has no `ProviderWindow`, so
     *    the answer is simply absent and the run is byte-identical to the one before this slice —
     *    the planBoard doctrine this file already follows for the orchestrator's parameters. It is
     *    not silent about it: `asked` is still true, so {@see ContextWindow::couldNotAsk()} says
     *    the ceiling went unverified.
     *  - **Never twice.** {@see $medido} holds the answer, the silence included.
     *
     * Nothing here can raise: `ProviderWindow` answers `null` for unreachable, non-JSON, missing,
     * zero, negative, and for a provider that exposes only the window its MODEL was trained for.
     */
    public static function measuredContextTokens(?Config $config): ?int
    {
        $base = self::baseUrl($config);
        if ($base === null || !class_exists(ProviderWindow::class)) {
            return null;
        }
        if (\array_key_exists($base, self::$medido)) {
            return self::$medido[$base];
        }

        return self::$medido[$base] = (new ProviderWindow($base, self::$costura))->tokens();
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
     * WHERE THE ENDPOINT'S VALUE CAME FROM — the only honest thing to show beside an unreachable one.
     *
     * «unreachable: http://llama.local:11438» sends a person to fix a machine when the value was
     * never theirs: it was a package's fallback, or a stray variable in a shell they forgot. Saying
     * WHICH of the three answered turns the same red into an instruction — declare it, or unset the
     * variable that is winning, or start the host you actually named.
     *
     * It resolves here rather than in whatever surface wants to paint it, for the reason this whole
     * class exists: a second reader of one precedence is the defect that {@see evidence/0165}
     * measured, and a THIRD copy is what a surface computing its own provenance would be.
     *
     * @return 'config'|'environment'|'none'
     */
    public static function baseUrlSource(?Config $config): string
    {
        $declarado = $config?->get('agent.baseUrl');
        if (\is_string($declarado) && $declarado !== '') {
            return 'config';
        }
        $entorno = getenv('MILPA_AGENT_BASE_URL');

        return \is_string($entorno) && $entorno !== '' ? 'environment' : 'none';
    }

    /**
     * The same question about the model's name, answered by the same precedence.
     *
     * @return 'config'|'environment'|'none'
     */
    public static function modelSource(?Config $config): string
    {
        $declarado = $config?->get('agent.model');
        if (\is_string($declarado) && $declarado !== '') {
            return 'config';
        }
        $entorno = getenv('MILPA_AGENT_MODEL');

        return \is_string($entorno) && $entorno !== '' ? 'environment' : 'none';
    }

    /**
     * WHETHER A MODEL ANSWERS, asked of the endpoint this app's turns actually use.
     *
     * Nothing could say «there is no reachable model»: every surface READ the configured name and
     * printed it, so a house whose provider was down looked identical to one talking happily
     * (greenhouse decisions/0266). The answer includes the arm nobody was checking — whether the
     * provider serves the model this house DECLARES — because a catalogue that does not contain it
     * fails every turn AT the provider, and the failure looks like a bug in the turn.
     *
     * `null` means the question was never asked, and the three guards are the ones
     * {@see measuredContextTokens()} already established for the same door:
     *
     *  - **No base URL, no question.** Inventing a host to interrogate is egress nobody asked for.
     *  - **No reader, no question.** `milpa/ai-gateway` older than the one that ships
     *    {@see ProviderReach} answers nothing, and the run is byte-identical to the one before.
     *  - **Never twice.** Memoised by endpoint, the silence included.
     *
     * Nothing here can raise: `ProviderReach` promises an unanswered question, never an exception.
     *
     * @return null|array{reached: bool, models: list<string>, serves_declared: null|bool}
     */
    public static function providerReach(?Config $config): ?array
    {
        $base = self::baseUrl($config);
        if ($base === null || !class_exists(ProviderReach::class)) {
            return null;
        }
        $model = self::model($config) ?? '';
        $key = $base . "\0" . $model;
        if (\array_key_exists($key, self::$alcance)) {
            return self::$alcance[$key];
        }
        $reach = new ProviderReach($base, $model, self::$costura);

        return self::$alcance[$key] = [
            'reached' => $reach->reached(),
            'models' => $reach->models(),
            'serves_declared' => $reach->offersDeclared(),
        ];
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
