<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\Principal;

/**
 * Whoever can write down that an operation was MATERIALISED.
 *
 * This is deliberately not {@see \Milpa\AiGateway\ToolCallRecorder}. That contract receives `tool`,
 * `arguments`, `result` and `ok` — everything you need to describe a CALL and nothing you need to
 * describe a FACT. Its implementor is told about the call after it returned, so it can only write what
 * it was handed, and what it was handed has nowhere to put either the effect or the executor.
 *
 * Two identities travel here and they are not the same one twice. In today's CLI they coincide,
 * because a single process asks, authorises and runs; the moment a session pauses and someone else
 * resumes it, they stop coinciding — measured, with a record that said "rod authorised it" about an
 * effect another principal produced. True, and the wrong truth.
 *
 * Neither identity may be rebuilt afterwards from whoever reads the stream. A durable fact whose
 * author depends on its reader is two incompatible histories written from one source.
 *
 * Grounded in the greenhouse: `decisions/0037` (H-ATTRIBUTION-1) and `evidence/0209`–`0212`.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
interface ExecutionRecorder
{
    /**
     * Writes down that an operation was materialised, with the two identities kept apart.
     *
     * It is called only when something actually ran — never for a call that merely asked for
     * confirmation, because an attempt recorded as a fact is worse than an unrecorded one.
     *
     * @param string                                                           $operation       the canonical operation identity, never a surface spelling
     * @param ?Principal                                                       $executedBy      observed when the effect happened; `null` is an honest gap
     * @param string                                                           $executorSource  where that observation came from, so a reader can weigh it
     * @param ?array{principal: ?string, provenance: string, session: ?string} $authorizedBy
     *                                                                                          the authority that covered this call, or `null` — which says
     *                                                                                          plainly that none did, and saying it is not the same as
     *                                                                                          staying silent
     * @param string                                                           $argumentsDigest a reference to the arguments, not a second copy of them
     */
    public function executed(
        string $operation,
        ?Principal $executedBy,
        string $executorSource,
        ?array $authorizedBy,
        string $argumentsDigest,
    ): void;
}
