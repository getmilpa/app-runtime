<?php

/**
 * This file is part of milpa/app-runtime.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\Command\{Operation, InvocationContext};
use Milpa\Command\Effect\{Authority, EffectProfile, Externality, Mutation, Reversibility, Subject};
use Milpa\ToolRuntime\Contracts\ToolContext;

/** A closed self-log producer: the name, schema, effect and implementation cannot be supplied by a caller. */
final readonly class SessionDebtWriteOperation extends Operation
{
    public function __construct(private SessionHouseDebt $debt)
    {
        parent::__construct(
            name: SessionHouseDebt::REPORT,
            description: 'Record bounded testimony about friction in this session; not permission or progress. First use HouseDebtRead mode=calls and always supply available ids in about. In statement say what you tried, expected, the literal observed code/refusal, and the workaround or inability to continue. The host resolves evidence; it never trusts a code in your prose. Eight new reports per session; exact repeats return the original acknowledgement. Secret values are redacted before storage.',
            handler: fn (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array =>
                $this->debt->report($input, $authority?->extra[JournalAppendWitness::CONTEXT_KEY] ?? null),
            scopes: ['agent:run'],
            inputSchema: [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['owner', 'kind', 'statement'],
                'properties' => [
                    'owner' => ['type' => 'string', 'maxLength' => 140, 'description' => 'house, environment, model, user, unknown, plugin:<lowercase kebab id up to 24 characters>, or mcp:<server up to 32 letters/digits/_/->'],
                    'kind' => ['type' => 'string', 'enum' => SessionHouseDebt::KINDS],
                    'statement' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                    'about' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'string', 'maxLength' => 128]],
                    'disputes' => ['type' => 'string', 'maxLength' => 64],
                ],
            ],
            mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Compensatable,
                authority: Authority::None,
                subject: Subject::Data,
            ),
            surfaces: ['mcp'],
        );
    }

    /** The exact store and session, not merely a matching tool name. */
    public function belongsTo(SessionStore $store, string $session): bool
    {
        return $this->debt->belongsTo($store, $session);
    }

    /** Begin a fresh observation for this actual bridge invocation.
     * @param array<string, mixed> $arguments
     */
    public function witness(object $owner, array $arguments): JournalAppendWitness
    {
        return $this->debt->witness($owner, $arguments);
    }
}
