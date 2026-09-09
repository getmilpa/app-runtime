<?php

/**
 * This file is part of Milpa App Runtime — the agent runtime a Milpa app installs.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Live\PresentationOverrideStore;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * Allowing somebody to change what somebody else's component looks like.
 *
 * Shipping your own component is construction, and construction concedes nothing: install the
 * package and it is there, no ceremony, no grant. Changing what ANOTHER package's component looks
 * like is an effect on a resource that is not yours, and `decisions/0246` §2 says it passes through
 * a human. These are that passage.
 *
 * The authority is `Privileged` and not merely a write, because the axis asks whose authority is
 * spent: this acts on *other people's resources*, which is the definition. It is the reason a grant
 * is worth a ceremony at all — nothing here writes application data, it changes whose CSS reaches
 * whose markup.
 */
final class PresentationOverrideOperations implements CommandProvider
{
    public function __construct(private readonly PresentationOverrideStore $store)
    {
    }

    /**
     * Grant, withdraw, and read the standing grants.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'ui:override:grant',
                effects: new EffectProfile(
                    // The ledger the transport reads before it emits a page.
                    Mutation::Persistent,
                    // Nothing leaves: the files are already inside this app, which the store enforces.
                    Externality::None,
                    // GUARANTEED, and it names the inverse rather than describing one. `ui:override:revoke`
                    // is shipped in this same table, takes the same `component`, and removes the entry —
                    // so a grant is undone by an operation somebody can run, not by a sentence somebody
                    // has to follow. The contract NAMES the operation and does not spell its arguments:
                    // a compensation CITES its inverse rather than copying its call, which the house's
                    // own gate enforces and caught this line writing prose the first time.
                    Reversibility::Guaranteed,
                    // Other people's resources: this decides whose stylesheet reaches whose component.
                    Authority::Privileged,
                    // CONFIGURATION, not executable — and that is only true because an override may
                    // not carry a script. The same classes keep loading and the component looks
                    // different; the moment a grant could ship behaviour, the subject would be
                    // `Executable` and this ceremony would be understating what it does. The refusal
                    // in the orchestrator is what keeps this line honest.
                    subject: Subject::Configuration,
                    rollbackContract: 'ui:override:revoke',
                ),
                description: "Allow a package to change what another package's component looks like",
                handler: fn (array $input): array => $this->grant($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['component', 'by'],
                    'properties' => [
                        'component' => ['type' => 'string', 'description' => 'The component whose look changes — the one being overridden, not the one doing it'],
                        'by' => ['type' => 'string', 'description' => 'Who is asking, for the record: the package or app the override comes from'],
                        'styles' => ['type' => 'string', 'description' => 'A stylesheet inside this app, emitted AFTER the component\'s own so the cascade does the work'],
                        'messages' => ['type' => 'string', 'description' => 'A message catalogue inside this app; it may say a declared word differently, never invent one'],
                    ],
                ],
                mutating: true,
                requiresConfirmation: true,
            ),
            new Operation(
                name: 'ui:override:revoke',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // Undone by granting again — the ledger still shows what to grant.
                    Reversibility::Guaranteed,
                    Authority::Privileged,
                    subject: Subject::Configuration,
                    rollbackContract: 'ui:override:grant',
                ),
                description: 'Withdraw a granted override, so the component goes back to its own look',
                handler: fn (array $input): array => $this->revoke($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['component'],
                    'properties' => ['component' => ['type' => 'string']],
                ],
                mutating: true,
                requiresConfirmation: true,
            ),
            new Operation(
                name: 'ui:override:list',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    // A read has nothing to take back, and the house refuses to let it claim otherwise.
                    Reversibility::NotApplicable,
                    Authority::Read,
                    subject: Subject::None,
                ),
                description: 'What overrides this house has authorized, and who authorized each one',
                handler: fn (array $input): array => ['overrides' => $this->store->all()],
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function grant(array $input): array
    {
        $component = \is_string($input['component'] ?? null) ? $input['component'] : '';
        $by = \is_string($input['by'] ?? null) ? $input['by'] : '';

        if ($component === '' || $by === '') {
            throw new \InvalidArgumentException('A grant names the component whose look changes and who is asking.');
        }

        // WHO AUTHORIZED IT is read from the call, never invented here. `authorized_by` and the
        // executor are different facts, and a value this method chose for itself would collapse them
        // into one — which is the thing the ledger exists to keep apart (greenhouse decisions/0240,
        // invariant 3: executing a decision does not make the executor the owner of the criterion).
        $authorizedBy = \is_string($input['authorized_by'] ?? null) && $input['authorized_by'] !== ''
            ? (string) $input['authorized_by']
            : 'unknown';

        $replaced = $this->store->grant(
            $component,
            \is_string($input['styles'] ?? null) ? $input['styles'] : null,
            \is_string($input['messages'] ?? null) ? $input['messages'] : null,
            $by,
            $authorizedBy,
        );

        return [
            'granted' => $component,
            'by' => $by,
            'authorized_by' => $authorizedBy,
            'replaced' => $replaced,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function revoke(array $input): array
    {
        $component = \is_string($input['component'] ?? null) ? $input['component'] : '';

        if ($component === '') {
            throw new \InvalidArgumentException('Name the component whose grant is being withdrawn.');
        }

        return ['revoked' => $component, 'was_granted' => $this->store->revoke($component)];
    }
}
