<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

// A STAND-IN FOR `resources/trial-run.php` that asks, from INSIDE the trial's confinement, what a writer of
// the house asks before it writes (greenhouse decisions/0515): does a fresh process boot this copy? The copy
// is a TinyHouse, so its vendor/autoload.php reaches this package's own classes.
require __DIR__ . '/vendor/autoload.php';

$operation = $argv[1] ?? '';
$out = ['ok' => true, 'dev_null_opens' => @fopen('/dev/null', 'r') !== false, 'dev_zero_opens' => @fopen('/dev/zero', 'r') !== false];

if ($operation === 'probe') {
    $out['why_not'] = (new Milpa\AppRuntime\Support\BootProbe())->whyNot(__DIR__);
}
if ($operation === 'disable') {
    $out['boot'] = (new Milpa\AppRuntime\Support\HouseBootWitness(__DIR__))->writeIfItBoots(
        ['config/plugins.php' => "<?php return [];\n"],
        static fn () => file_put_contents(__DIR__ . '/config/plugins.php', "<?php return [];\n"),
    );
    $out['plugins'] = file_get_contents(__DIR__ . '/config/plugins.php');
}
if ($operation === 'observe') {
    $out['routes'] = (new Milpa\AppRuntime\Agent\HouseRouteObserver())->observe(__DIR__, ['config/plugins.php']);
}

if ($operation === 'git') {
    // What framework:apply and a secret's overlay ask git before they write — from inside the confinement.
    $wayBack = new ReflectionMethod(Milpa\AppRuntime\Framework\FrameworkUpdate::class, 'gitCannotBeTheWayBack');
    $out['way_back'] = $wayBack->invoke(null, __DIR__, ['config/app.php']);
    $ignore = new ReflectionMethod(Milpa\AppRuntime\Operations\ConfigOperations::class, 'gitignoreMissing');
    $out['ignore_missing'] = $ignore->invoke(null, __DIR__);
}

echo json_encode($out, \JSON_UNESCAPED_SLASHES), "\n";
