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

use Milpa\Command\Operation;
use Milpa\Console\Events\ConsoleEvents;
use Milpa\Console\Events\OperationExecutingEvent;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * The HTTP policy's verdict on one permissioned operation, carried to the boundary for that run only.
 *
 * `HttpProjector` runs an operation typed by `permission` on the `http` surface only after the host's
 * `OperationHttpPolicy` admitted it; without a policy it refuses to serve it at all. That run is the verdict —
 * the authority it hands the handler is not: a web `ToolContext` travels on to every call the handler makes
 * (an agent turn started over HTTP hands it to its model's tools), and a channel says where an authority came
 * from, never whose permission was judged (greenhouse decisions/0544).
 *
 * So the mark is the run, not the authority: set when the runner starts that operation on the `http` surface,
 * taken by the boundary for that same operation object, and wiped by every other run and by the end of any run.
 * A call the handler makes starts its own run, so it finds no mark; a run a listener stopped before the boundary
 * leaves none behind.
 */
final class JudgedPermission
{
    private ?Operation $judged = null;

    /** A mark that follows the runs this dispatcher announces. */
    public static function listen(MilpaEventDispatcherInterface $events): self
    {
        $mark = new self();
        $events->subscribe(ConsoleEvents::EXECUTING, $mark->executing(...), \PHP_INT_MIN);
        $events->subscribe(ConsoleEvents::EXECUTED, $mark->executed(...));

        return $mark;
    }

    /**
     * A run starts: it is the verdict only when it is a permissioned operation on the HTTP surface.
     *
     * @param array<string, mixed> $payload
     */
    public function executing(string $event, array $payload): void
    {
        $run = $payload['event'] ?? null;
        $this->judged = $run instanceof OperationExecutingEvent && $run->surface === 'http' && $run->operation->permission !== null
            ? $run->operation
            : null;
    }

    /** A run ended, however it ended: nothing it judged outlives it. */
    public function executed(): void
    {
        $this->judged = null;
    }

    /** Whether the run now reaching the boundary is the one the HTTP policy judged — asked once, then gone. */
    public function take(Operation $operation): bool
    {
        $judged = $this->judged === $operation;
        $this->judged = null;

        return $judged;
    }
}
