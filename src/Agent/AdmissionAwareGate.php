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

use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\PolicyRuleProviderInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;

/**
 * A registry's gate, asked the way the house judges a seat's call to a built verb (greenhouse decisions/0590).
 *
 * The tool runtime's gate asks for the word a tool declares before it asks the house's policy, and it knows nothing
 * of admissions. This is the same gate — every question is forwarded to it, and what is set here is set there —
 * except that a seat's call to a built verb is asked with that verb's own words in hand, so the question that
 * decides it is the policy's: did a person admit it. The words are handed only when that policy IS the gate's
 * ({@see PluginAuthoringPolicy::contextFor()}); with any other policy on the gate, nothing changes.
 */
final class AdmissionAwareGate extends PolicyGate
{
    public function __construct(private readonly PolicyGate $inner)
    {
        parent::__construct();
    }

    /** The caller as the wrapped gate is to see it for this one tool. */
    public function contextFor(ToolContext $ctx, string $tool): ToolContext
    {
        $policy = $this->inner->getCallPolicy();

        return $policy instanceof PluginAuthoringPolicy ? $policy->contextFor($ctx, $tool) : $ctx;
    }

    /**
     * The wrapped gate's whole verdict for a call, asked with the caller as it is to be seen for this tool.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(ToolContext $ctx, ToolDefinition $tool, array $arguments = []): AuthorizationResult
    {
        return $this->inner->authorize($this->contextFor($ctx, $tool->name), $tool, $arguments);
    }

    /**
     * The wrapped gate's question about the declared word — which, for a seat's call to a built verb, is not the
     * question that decides: the caller is seen holding that verb's own words.
     *
     * @param array<string> $scopes
     */
    public function authorizeScopes(ToolContext $ctx, string $name, array $scopes): AuthorizationResult
    {
        return $this->inner->authorizeScopes($this->contextFor($ctx, $name), $name, $scopes);
    }

    /**
     * The wrapped gate's verdict before a call — the declared word, then the house's policy — asked the same way,
     * so for a seat's call to a built verb the policy's answer is the one that stands.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorizeCall(ToolContext $ctx, ToolDefinition $tool, array $arguments): AuthorizationResult
    {
        return $this->inner->authorizeCall($this->contextFor($ctx, $tool->name), $tool, $arguments);
    }

    /** Forwards to the wrapped gate. */
    public function setCallPolicy(CallPolicy $policy): void
    {
        $this->inner->setCallPolicy($policy);
    }

    /** Forwards to the wrapped gate. */
    public function getCallPolicy(): ?CallPolicy
    {
        return $this->inner->getCallPolicy();
    }

    /** Forwards to the wrapped gate. */
    public function setRuleProvider(PolicyRuleProviderInterface $provider): void
    {
        $this->inner->setRuleProvider($provider);
    }

    /** Forwards to the wrapped gate. */
    public function getRuleProvider(): ?PolicyRuleProviderInterface
    {
        return $this->inner->getRuleProvider();
    }

    /** Forwards to the wrapped gate. */
    public function hasRuleProvider(): bool
    {
        return $this->inner->hasRuleProvider();
    }

    /**
     * Forwards to the wrapped gate.
     *
     * @return array<string, mixed>
     */
    public function channelPolicy(string $channel): array
    {
        return $this->inner->channelPolicy($channel);
    }

    /**
     * Forwards to the wrapped gate.
     *
     * @param array<string, mixed> $policy
     */
    public function setChannelPolicy(string $channel, array $policy): void
    {
        $this->inner->setChannelPolicy($channel, $policy);
    }

    /** Forwards to the wrapped gate. */
    public function requiresConfirmation(ToolContext $ctx, ToolDefinition $tool): bool
    {
        return $this->inner->requiresConfirmation($ctx, $tool);
    }
}
