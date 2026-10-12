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

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Agent\AdmissionAwareRegistry;
use Milpa\AppRuntime\Agent\ConfinedWork;
use Milpa\AppRuntime\Agent\HouseWork;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingOperations;
use Milpa\AppRuntime\Tests\Standing\Plugins\Shelf\LabShelf;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
use Milpa\Console\OperationRunner;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

/**
 * What an AGENT LEG calls through, over a {@see LabHouse}: the registry `AgentOperations` hands the model when
 * trials are on — `TrialAwareRegistry` over `AdmissionAwareRegistry` over the tools of the catalogue — with the
 * house's work layer, and the same tools with trials off.
 *
 * {@see LabHouse::through()} is the door of a terminal: gate, policy, boundary, in one process. A leg is another
 * door. A call it plans as a trial runs in a COPY of the house, and one that is work in the domain runs in the house
 * in ANOTHER process; neither reaches the handler the registry holds. A property measured through the first door
 * says nothing of the second (greenhouse decisions/0615, «Lo que la segunda revisión encontró», 1).
 *
 * WHAT IS REAL HERE AND WHAT IS NOT. The routing is the package's own: `TrialAwareRegistry`, `TrialRouter`,
 * `TrialRunner`, `HouseWork`, `ConfinedWork`. The sandbox confines nothing — the stand-in the suite uses wherever it
 * tests routing — and the two runner scripts are stand-ins for `resources/trial-run.php` and `resources/work-run.php`
 * that do the one thing those do: build the house's boundary over the tree they are given and run the operation
 * through `OperationRunner` WITH NO CALLER'S AUTHORITY. The real scripts need a composed app; they run in cattle
 * (greenhouse evidence/1194).
 */
final class LabLeg
{
    /** What a leg with trials on calls through. */
    public TrialAwareRegistry $on;

    /** The same tools with trials off: straight to the house's boundary. */
    public AdmissionAwareRegistry $off;

    public TrialRouter $router;

    /** @var list<string> */
    private array $scratch = [];

    /**
     * @param list<Operation> $operations what this leg's catalogue holds
     * @param LabShelf|null   $plugin     the plugin whose work runs in the house, when the leg has a work layer
     * @param \Closure|null   $between    called after the gate judged a call and before it runs: a test's way of doing
     *                                    something in that moment
     * @param \Closure|null   $onTheGate  what the container holds as its `CallPolicy` when this leg boots, given the
     *                                    house's own — an app may register one of its own, a decorator, or none
     *                                    (`false`); with no closure it is the house's, as `install()` leaves it
     */
    public function __construct(private readonly LabHouse $house, array $operations, ?LabShelf $plugin = null, ?\Closure $between = null, ?\Closure $onTheGate = null)
    {
        $bwrap = $this->file('bwrap', "#!/bin/sh\nwhile [ \"$1\" != \"--\" ] && [ $# -gt 0 ]; do shift; done\nshift\nexec \"$@\"\n");
        chmod($bwrap, 0o755);
        $autoload = var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $runs = '\\' . self::class . '::asARunnerDoes';
        // The trial's runner is copied into the copy and finds its tree beside itself; the work's is handed the house.
        $trial = $this->file('trial-run.php', "<?php\nrequire {$autoload};\nexit({$runs}(__DIR__, \$argv[1] ?? '', \$argv[2] ?? '{}', 'trial'));\n");
        $work = $this->file('work-run.php', "<?php\nrequire {$autoload};\nexit({$runs}(\$argv[1] ?? '', \$argv[2] ?? '', \$argv[3] ?? '{}', 'work'));\n");

        $held = $onTheGate === null ? $house->policy : $onTheGate($house->policy);
        if ($held instanceof CallPolicy) {
            $house->container->has(CallPolicy::class) ? $house->container->replaceService(CallPolicy::class, $held) : $house->container->registerService(CallPolicy::class, $held);
        }
        // A leg is ONE process for all its calls, and it boots here: what it loaded is what the disk held now.
        $house->boots();
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll($operations, $registry, $house->container);
        $this->off = new AdmissionAwareRegistry($registry);
        $runner = new TrialRunner(bwrap: $bwrap);
        $this->router = new TrialRouter($house->root, $runner, $trial);
        // As `AgentOperations` builds it: the leaving doors are handed the house's judge, whatever the gate holds.
        $this->on = new TrialAwareRegistry($this->off, $this->router, $operations, judge: \Milpa\AppRuntime\Agent\PluginAuthoringPolicy::judgeOf($house->container));
        if ($plugin !== null) {
            $plugin->between = $between;
            $this->on->runsWorkInTheHouse(new HouseWork($house->root, [$plugin], null, $runner), new ConfinedWork($house->root, $runner, $work));
        }
    }

    /** A seat as a leg carries it: the key, what the ledger says it holds, and its own signature on its calls. */
    public function seat(string $key = LabHouse::SEAT): ToolContext
    {
        $known = $this->house->contextOf($key);

        return new ToolContext(principal: $known->principal, channel: 'cli', scopes: $known->scopes, extra: ['signer.fingerprint' => $key]);
    }

    public function destroy(): void
    {
        array_map(static fn (string $file): bool => @unlink($file), $this->scratch);
    }

    /**
     * What `resources/trial-run.php` and `resources/work-run.php` do, over a lab house: the boundary of the tree they
     * are given, and the operation run through it with no caller's authority.
     */
    public static function asARunnerDoes(string $root, string $name, string $json, string $surface): int
    {
        $input = json_decode($json, true);
        $input = \is_array($input) ? $input : [];
        $verifier = new class () implements SignatureVerifier {
            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                foreach ([LabHouse::PERSON, LabHouse::SEAT, LabHouse::OTHER_SEAT, LabHouse::STRANGER] as $key) {
                    if (hash_equals('lab-signature:' . $key . ':' . hash('sha256', $payload), $signature)) {
                        return new VerifiedSigner($key);
                    }
                }

                return null;
            }
        };
        $container = new DIContainer();
        // THE PROCESS A LEG STARTS DOES NOT SEE THE BOOK. The real runner masks the book and its head from every
        // confined process (`TrialRunner::maskArgs()`), and a copy is made without them; this stand-in confines
        // nothing, so the judge of the process it stands for is handed a place where no book is. Without that, a
        // watcher run «out of process» wrote its own line in the house's book from here, and the suite could not see
        // that the house itself heard nothing (the fourth review, H1).
        $container->registerService(OperationBoundary::class, new PluginAuthoringPolicy($root, standing: new HouseStandingJudge($root . '/storage/identity/as-masked-from-a-confined-process', $verifier)));
        $operations = PluginAuthoringPolicy::catalogue($container, [
            ...(new LabShelf($root))->operations(),
            ...(new StandingOperations($container, $root, $verifier))->operations(),
        ]);
        foreach ($operations as $operation) {
            if ($operation->name !== $name) {
                continue;
            }
            try {
                $result = (new OperationRunner($container))->run($operation, $input, $surface);
            } catch (\Throwable $error) {
                $result = ['ok' => false, 'error' => $error::class . ': ' . $error->getMessage()];
            }
            echo json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), "\n";

            return \is_array($result) && (\array_key_exists('ok', $result) && $result['ok'] !== true || \array_key_exists('error', $result)) ? 1 : 0;
        }
        echo json_encode(['ok' => false, 'error' => "no operation «{$name}» in this app"]), "\n";

        return 1;
    }

    private function file(string $name, string $contents): string
    {
        $path = $this->house->root . '-' . $name;
        file_put_contents($path, $contents);

        return $this->scratch[] = $path;
    }
}
