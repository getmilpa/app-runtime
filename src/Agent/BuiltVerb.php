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

use Milpa\Command\DeclaredCondition;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;

/**
 * One verb of a capability built in this house, with the contract it declares today (greenhouse decisions/0590).
 *
 * The contract is what a person is shown before admitting the verb, and its digest is what the admission pins: a
 * verb whose declaration moved afterwards — another effect, another input, another scope, no confirmation — is no
 * longer the verb that was admitted. It is read from the declaration and never from the code behind it: the house
 * holds a verb to what it declares, it does not read what its handler does.
 *
 * WHERE ITS STATE LIVES is part of that contract (greenhouse decisions/0588, rule 3): the paths a call of work may
 * write, and whether the plugin declared them or they are the store of its entities. A verb that moves its state
 * elsewhere is another verb. Whether the house can confine a process right now, or how large that state has grown,
 * is not: those are facts of the moment, asked when a card is drawn ({@see BuiltCapabilities::runs()}).
 */
final class BuiltVerb
{
    /** How the scope of a verb that declares none is spelled: itself, so it is admitted one by one. */
    public const string ITSELF = '=';

    /** @var array{paths: list<string>, source: string, refused?: string}|null|false false until it is asked */
    private array|null|false $state = false;

    /**
     * @param (\Closure(Operation): ?WorkPlan)|null $planOf how the house would run a call of this verb as work, read from
     *                                                      its declaration alone; without it the verb declares no state
     */
    public function __construct(public readonly string $capability, public readonly Operation $operation, private readonly ?\Closure $planOf = null)
    {
    }

    /**
     * Where this verb's work keeps its state, as declared — or null when it is not work in the domain: it reads, or
     * it lands the way authoring does, by a trial and a promotion.
     *
     * @return array{paths: list<string>, source: string, refused?: string}|null
     */
    public function state(): ?array
    {
        if ($this->state !== false) {
            return $this->state;
        }
        $plan = $this->planOf === null ? null : ($this->planOf)($this->operation);

        return $this->state = $plan === null ? null : ['paths' => $plan->state, 'source' => $plan->source] + ($plan->refused === null ? [] : ['refused' => $plan->refused]);
    }

    /** The verb as a tool is called. */
    public function tool(): string
    {
        return McpProjector::toolName($this->operation->name);
    }

    /**
     * What an admission of this verb is given under: each scope it declares, or — declaring none — itself.
     *
     * @return non-empty-list<string>
     */
    public function scopes(): array
    {
        $declared = array_values(array_unique(array_filter($this->operation->scopes, static fn (string $scope): bool => $scope !== '')));

        return $declared === [] ? [self::ITSELF . $this->operation->name] : $declared;
    }

    /**
     * Why a person cannot be asked to admit this verb, or null (decisions/0590 rule 3): what a declaration does not
     * say never lowers a control.
     */
    public function notAdmissible(): ?string
    {
        if (!$this->operation->effectCeiling()->isFullyClassified()) {
            return \sprintf('«%s» does not declare its effects', $this->operation->name);
        }
        if ($this->operation->mutating && $this->operation->scopes === [] && $this->operation->permission === null) {
            return \sprintf('«%s» changes state and declares no scope', $this->operation->name);
        }
        if (\is_string($this->state()['refused'] ?? null)) {
            return \sprintf('«%s» keeps its state where the house keeps none', $this->operation->name);
        }

        return null;
    }

    /**
     * The verb's declared contract, as a person reads it.
     *
     * @return array<string, mixed>
     */
    public function contract(): array
    {
        $operation = $this->operation;
        $effects = $operation->effectCeiling();
        $scopes = array_values(array_unique($operation->scopes));
        sort($scopes);

        return [
            'name' => $operation->name,
            'description' => $operation->description,
            'inputs' => $operation->inputSchema,
            'scopes' => $scopes,
            'permission' => $operation->permission,
            'effects' => $effects->toArray(),
            'mutating' => $operation->mutating,
            'requiresConfirmation' => $operation->requiresConfirmation,
            'namedTarget' => $operation->namedTarget,
            'createsNamedTarget' => $operation->createsNamedTarget,
            'surfaces' => $operation->surfaces,
            'path' => $operation->path,
            'preconditions' => array_map(static fn (DeclaredCondition $c): array => $c->toArray(), $operation->preconditions),
            'postconditions' => array_map(static fn (DeclaredCondition $c): array => $c->toArray(), $operation->postconditions),
            'artifacts' => $operation->artifacts,
            'observableEvidence' => $operation->observableEvidence,
            'state' => $this->state(),
        ];
    }

    /** The digest of that contract in one canonical form: what an admission pins. */
    public function digest(): string
    {
        return self::digestOf($this->contract());
    }

    /**
     * One canonical spelling for a declared structure: through JSON, with every object's keys in order.
     *
     * @param array<string, mixed> $declared
     */
    public static function digestOf(array $declared): string
    {
        $plain = json_decode((string) json_encode($declared, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), true);

        return 'sha256:' . hash('sha256', (string) json_encode(self::ordered($plain), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private static function ordered(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::ordered(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
