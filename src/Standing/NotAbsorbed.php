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

namespace Milpa\AppRuntime\Standing;

/**
 * The judge of standing grants did not let an act pass, and NOTHING RAN (greenhouse decisions/0615): the grant that
 * named the call does not stand at the moment of the act, or the line that would say the house let it pass could not
 * be written.
 *
 * A kind of its own because a run that leaves the process — a leg's trial, work confined in the house — has other
 * ways to fail, and whoever starts one has to tell this apart from them: this is a refusal with its reason, to be
 * answered as one, never an accident of the run.
 */
final class NotAbsorbed extends \RuntimeException
{
}
