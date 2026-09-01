<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\Command\Consent\OperationId;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Operation;

/**
 * Scoped consent an OPERATOR confers at launch — the same fact a mid-session yes produces, seeded
 * before the first step instead of extracted one pause at a time.
 *
 * ── THE DEBT THIS PAYS, MEASURED (greenhouse evidence/0442) ─────────────────────────────────────
 *
 * A headless run has no human mid-run: `plugins_register` was denied twice — «needs explicit
 * consent and channel 'cli' takes consent as a signature naming this call — none was presented» —
 * and the deliverable could not close. In ask mode the same debt shows as consent fatigue: a human
 * typing «continúa» five times, authorizing mechanics rather than judgment.
 *
 * ── ONE STORE, TWO JUDGES ───────────────────────────────────────────────────────────────────────
 *
 * Seeding appends exactly the facts a human yes leaves in the stream — the permission question with
 * the structured fact inside, the affirmative answer, the grant — so BOTH existing judges read it
 * with no new machinery: `Session::allows()` at the policy layer folds the `PermissionGranted`
 * event, and the `ConsentBridge`'s `ConsentGrant` list is rebuilt from the answered decision with
 * its argument constraints attached. There is no second store to drift from the first.
 *
 * ── WHAT DISTINGUISHES IT FROM A MID-SESSION YES ────────────────────────────────────────────────
 *
 * The answer's `executor` is {@see self::EXECUTOR}: an auditor reading the stream — or the rebuilt
 * grant's provenance — can tell a launch grant from an answer typed at a pause. The authority is
 * the same kind; the moment and the channel are not, and the record says so.
 *
 * ── THE DOCTRINE LIMIT: SIGNATURE-CLASS STAYS UNGRANTABLE ───────────────────────────────────────
 *
 * An operation whose declared ceiling carries {@see Authority::Privileged} is an institutional act
 * — it decides what the app can do or whom it believes (`identity:*`, `capabilities:enable`) — and
 * its consent is a signature naming the call. A grant cannot replace it, so seeding one is REFUSED
 * with the doctrine named, never silently narrowed. The criterion is read from the operation's
 * declared contract, not from a list of names somebody has to remember.
 */
final class LaunchGrants
{
    /**
     * The executor recorded on the seeded answer — how an auditor tells a launch grant apart from a
     * yes typed at a mid-session pause. It also travels as the rebuilt `ConsentGrant`'s provenance.
     */
    public const EXECUTOR = 'operator-launch';

    /** A reason cap: the verdict names facts, it does not dump them. */
    private const MAX_ARGUMENT_PAIRS = 16;

    /**
     * Parse the launch-grant input into entries, or return the reason it cannot be parsed.
     *
     * The CLI hands `--grant=a,b` over as a string; a programmatic call may hand the list. Each
     * entry is `op` or `op:key=value[;key2=value2]`; the operation may itself contain colons
     * (`plugins:register:plugin=X`), so the argument separator is the LAST colon before the first
     * `=`. Refused whole on the first malformed entry: accepting the good half and dropping the
     * rest would leave the operator believing the whole instruction landed.
     *
     * @return list<array{operation: string, arguments: array<string, string>}>|string the entries,
     *                                                                                 or the refusal
     *                                                                                 reason
     */
    public static function parse(mixed $asked): array|string
    {
        $raws = \is_string($asked) ? explode(',', $asked) : (\is_array($asked) ? $asked : []);

        $entries = [];
        foreach ($raws as $raw) {
            if (! \is_string($raw) || trim($raw) === '') {
                continue;
            }
            $raw = trim($raw);

            $firstEquals = strpos($raw, '=');
            if ($firstEquals === false) {
                // A trailing colon is a truncated entry, not a bare operation: silently reading
                // «plugins_register:» as the argument-less form would WIDEN a scoped grant by typo —
                // exactly the accident an authority surface must refuse, not absorb.
                if (str_ends_with($raw, ':')) {
                    return "a grant entry ends in «:» with nothing after it — write the constraints or drop the colon: «{$raw}»";
                }
                $entries[] = ['operation' => $raw, 'arguments' => []];

                continue;
            }

            $separator = strrpos(substr($raw, 0, $firstEquals), ':');
            if ($separator === false || trim(substr($raw, 0, $separator)) === '') {
                return "a grant entry needs its operation before the arguments: «{$raw}»";
            }

            $arguments = [];
            foreach (explode(';', substr($raw, $separator + 1)) as $pair) {
                $keyValue = explode('=', $pair, 2);
                if (\count($keyValue) !== 2 || trim($keyValue[0]) === '') {
                    return "a grant argument is written key=value: «{$pair}» in «{$raw}»";
                }
                $arguments[trim($keyValue[0])] = trim($keyValue[1]);
            }
            if (\count($arguments) > self::MAX_ARGUMENT_PAIRS) {
                return "a grant entry carries too many argument constraints (max " . self::MAX_ARGUMENT_PAIRS . "): «{$raw}»";
            }

            $entries[] = ['operation' => trim(substr($raw, 0, $separator)), 'arguments' => $arguments];
        }

        return $entries;
    }

    /**
     * Seed every entry into the session as operator consent — once, all-or-nothing.
     *
     * Every entry is judged BEFORE anything is appended: an operation the catalogue does not
     * declare cannot be judged (the same fail-closed rule the gate applies, H-GATE-1), and a
     * signature-class operation is refused with its doctrine. Only when the whole brief is
     * admissible do the facts land, so a refusal never leaves a half-seeded session behind.
     *
     * Re-seeding the same entry into the same session appends nothing: the consent is already a
     * fact of the stream, and writing it twice would make one decision look like two.
     *
     * @param list<array{operation: string, arguments: array<string, string>}> $entries   parsed by {@see parse()}
     * @param list<Operation>                                                  $catalogue the app's declared operations
     *
     * @return array{error: string}|array{seeded: list<string>, already: list<string>}
     */
    public function seed(SessionStore $store, string $sessionId, array $entries, array $catalogue, Principal $by): array
    {
        $resolved = [];
        foreach ($entries as $entry) {
            $operation = $this->operationNamed($catalogue, $entry['operation']);
            if ($operation === null) {
                return ['error' => "«{$entry['operation']}» resolves to no operation of this app, so a grant for it cannot be judged"];
            }
            if ($this->demandsSignature($operation)) {
                return ['error' => "«{$operation->name}» demands a signature naming the call; a grant cannot replace it"];
            }
            $resolved[] = ['operation' => $operation->name, 'arguments' => $entry['arguments']];
        }

        $session = $store->load($sessionId);
        $seen = [];

        $seeded = [];
        $already = [];
        foreach ($resolved as $entry) {
            $key = $entry['operation'] . '|' . ConsentBridge::digest($entry['arguments']);
            if (isset($seen[$key]) || ($session instanceof Session && $this->alreadySeeded($session, $entry['operation'], $entry['arguments']))) {
                $already[] = $entry['operation'];

                continue;
            }
            $seen[$key] = true;

            // The SAME three facts a human yes leaves, in the same order: the question with the
            // structured fact inside (operation and arguments — what `grantsDeLaSesion` rebuilds
            // the ConsentGrant from), the affirmative answer with who and which process, and the
            // policy-layer grant. The question is real: the operator answered it at launch.
            $why = json_encode(
                ['operation' => $entry['operation'], 'arguments' => $entry['arguments']],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            );
            $store->ask($sessionId, new PendingQuestion(
                id: 'perm:' . $entry['operation'],
                question: "Launch grant: whoever ran this agent authorizes «{$entry['operation']}» in this session.",
                options: ['sí', 'no'],
                why: $why === false ? null : $why,
                reason: 'permission',
            ));
            $store->answer($sessionId, 'perm:' . $entry['operation'], 'sí', $by, self::EXECUTOR);
            $store->grant($sessionId, $entry['operation']);

            $seeded[] = $entry['operation'];
        }

        return ['seeded' => $seeded, 'already' => $already];
    }

    /**
     * Whether this session already carries this exact consent — same operation, same argument
     * constraints — whoever conferred it.
     *
     * Compared by canonical digest, the ONE recipe ({@see ConsentBridge::digest()}), so key order
     * cannot make one decision look like two.
     *
     * @param array<string, string> $arguments
     */
    private function alreadySeeded(Session $session, string $operation, array $arguments): bool
    {
        foreach (ContratoInstalado::arreglo($session, 'decisions') as $decision) {
            if (! \is_array($decision) || ($decision['reason'] ?? null) !== 'permission') {
                continue;
            }
            if (! AffirmativeAnswer::is((string) ($decision['answer'] ?? ''))) {
                continue;
            }
            $fact = json_decode(\is_string($decision['why'] ?? null) ? $decision['why'] : '', true);
            if (! \is_array($fact) || ($fact['operation'] ?? null) !== $operation) {
                continue;
            }
            $granted = \is_array($fact['arguments'] ?? null) ? $fact['arguments'] : [];
            if (ConsentBridge::digest($granted) === ConsentBridge::digest($arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declared operation this entry names — by identity, never by spelling.
     *
     * @param list<Operation> $catalogue
     */
    private function operationNamed(array $catalogue, string $named): ?Operation
    {
        $id = new OperationId($named);
        foreach ($catalogue as $operation) {
            if ($id->is($operation->name)) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Whether this operation's policy demands a signature naming the call — the class a grant can
     * never cover.
     *
     * Read from the declared contract: {@see Authority::Privileged} is the institutional act —
     * `identity:*` decides whom the house believes, `capabilities:enable` what it can do — whose
     * consent is a signature, per greenhouse decisions/0177. A name list here would be the
     * directory this family keeps refusing to build; the axis is the rule.
     */
    private function demandsSignature(Operation $operation): bool
    {
        return $operation->effectCeiling()->authority === Authority::Privileged;
    }
}
