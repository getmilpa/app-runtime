<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Rendering\ComponentRendererRegistry;

/** The framework supplies identity and transport; an app supplies collaborators isolated under this id. */
final readonly class PreviewEnvironment
{
    public function __construct(public string $id, public StateTransferCodecInterface $codec, public InMemoryComponentRegistry $components, public ComponentRendererRegistry $renderers)
    {
    }
}
