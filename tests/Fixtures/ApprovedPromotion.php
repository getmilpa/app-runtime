<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Fixtures;

use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Agent\WhatAPromotionLands;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * A fixed approval in fixtures testing another promotion boundary (scope, books, merge, undo). This does not model
 * the person or prove the consent flow: APromotionAtEveryDoorNeedsAPersonTest and the real-door lab do that. Freeze
 * the digest now, so a later change cannot turn this fixture into an approval of different bytes.
 */
final class ApprovedPromotion
{
    public static function of(ToolContext $caller, string $root, string $workspace): ToolContext
    {
        $trial = TrialWorkspace::open($root, $workspace);
        $digest = $trial === null ? null : WhatAPromotionLands::digest($trial);

        return new ToolContext(
            principal: $caller->principal,
            channel: $caller->channel,
            scopes: $caller->scopes,
            request_id: $caller->request_id,
            ip: $caller->ip,
            userAgent: $caller->userAgent,
            extra: array_replace($caller->extra, [TrialOperations::APPROVED_PROMOTION => static fn (string $id): ?string => $id === $workspace ? $digest : null]),
            mode: $caller->mode,
            resultBudget: $caller->resultBudget,
        );
    }
}
