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
use Milpa\Command\Operation;
use Milpa\Command\Effect\EffectProfile;

/** The session-bound journal reader, available in recovery without granting a tool-name exemption. */
final readonly class SessionDebtReadOperation extends Operation
{
    public function __construct(private SessionHouseDebt $debt)
    {
        parent::__construct(
            name: SessionHouseDebt::READ,
            description: 'Read this session\'s calls or debt findings. Copy call ids into HouseDebt.about; findings are observations or testimony, not adjudicated defects. Codes absent from retained receipts remain unavailable.',
            handler: fn (array $input): array => $this->debt->read($input),
            scopes: ['agent:run'],
            inputSchema: [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['calls', 'findings']],
                    'id' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                    'after' => ['type' => 'integer', 'minimum' => 0],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
                ],
            ],
            effects: EffectProfile::readOnly(),
            surfaces: ['mcp'],
        );
    }

    /** The gate checks both identities; sharing a session string is not sharing its store. */
    public function belongsTo(SessionStore $store, string $session): bool
    {
        return $this->debt->belongsTo($store, $session);
    }
}
