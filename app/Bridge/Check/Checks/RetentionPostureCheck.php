<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Retention\RetentionConfig;
use App\Bridge\Retention\RetentionGate;
use App\Bridge\Support\Finding;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * The retention posture leg (DL-199), migrated out of `CheckCommand::handle()`
 * (DL-242 stage 2).
 *
 * Retention is on by default and runs off the receiver, so a bad window is SILENT:
 * the stores just grow, which is the exact DL-012 failure the gate replaced. This
 * check reports the posture rather than letting it go unnoticed.
 *
 * IT NEVER YIELDS `fail`, and that is a property of the subject rather than of this
 * class: every posture it can report is either healthy or an operator-fixable
 * misconfiguration that leaves the receiver serving correctly. The caller still
 * routes its report through the same exit-deciding renderer as every other check —
 * the wiring belongs to the registry's contract, not to today's severity set.
 */
final class RetentionPostureCheck implements Check
{
    public function id(): string
    {
        return 'retention.posture';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $retention = RetentionConfig::fromConfig();

        if (! $retention->enabled) {
            yield Finding::warn('retention: DISABLED (BRIDGE_RETENTION_ENABLED=false) — nothing prunes webhook_events/agent_dispatches/inbox lines unless you schedule bridge:prune yourself; the append-only stores grow without bound (DL-012/DL-199).');

            return;
        }

        if (! $retention->isUsable()) {
            yield Finding::warn('retention: enabled but MISCONFIGURED — '.$retention->problem.'. Nothing is pruned (a bad window never falls back to a default cutoff). The stores grow until fixed.');

            return;
        }

        yield Finding::ok('retention: on ('.$retention->summary().')');

        // The whole no-latency claim rests on the response being FINISHED before
        // the terminating callback runs. Under PHP-FPM that is
        // fastcgi_finish_request(); without it (mod_php) Symfony only flushes, so
        // a keep-alive client can sit through the prune. The prune stays correct
        // either way — this degrades latency, silently, which is why it is worth
        // one preflight line.
        //
        // THE LINE IS `unvalidated` AND NOT `warn` BECAUSE NOTHING THIS PROCESS CAN SEE
        // ANSWERS THE QUESTION (card#5698 sub-shape (3)). The subject is the RECEIVER's
        // SAPI, and the only limb below that could answer for it is dead in a console
        // command — so what remains is an indicator, not a fact. See the probe's docblock.
        // It is raised only where that indicator is ABSENT, the one state worth an
        // operator's attention; a FOUND binary is no answer either, and so earns no green
        // line rather than an `ok` disclosing its own blindness (`Severity` corollary (A)).
        if (! $this->earlyFinishIndicated()) {
            yield Finding::unvalidated('retention: could NOT determine whether the receiver ends the request early — bridge:check is a console command, so the SAPI running it is not the receiver\'s and does not define fastcgi_finish_request(), and no php-fpm binary was findable on the PATH this process sees (a healthy FPM install can keep it in /usr/sbin, off a non-root PATH), so this run is no evidence either way. If the receiver is NOT served under PHP-FPM, retention runs AFTER the response is flushed but BEFORE the request ends, so a keep-alive client may wait for it. Confirm how the receiver is served (see CLAUDE_DEPLOYMENT.md); if it is not PHP-FPM, either serve it under PHP-FPM or set BRIDGE_RETENTION_ENABLED=false and run bridge:prune on a schedule.');
        }

        // Config being valid does NOT mean retention is RUNNING. A pass that throws
        // (unwritable inbox, ENOSPC, a DB fault) backs off a full interval and drains
        // nothing, leaving the posture line above reading healthy — the DL-012 blind
        // spot. The gate records its last throw here; surface it (cleared automatically
        // on the next successful pass). The catch stays with the check that needs it:
        // CheckRunner deliberately does not isolate, so hoisting this would turn an
        // unreachable cache backend into an aborted `bridge:check`.
        try {
            $lastError = Cache::get(RetentionGate::ERROR_KEY);
            if (is_array($lastError)) {
                // Deliberately does NOT assert the stores are growing: on a since-quieted
                // install nothing arrives, so nothing grows — the marker can outlive the
                // condition (webhook-driven clear, ≤30d TTL). State the fact (last pass
                // failed, nothing pruned since) and let the timestamp speak.
                yield Finding::warn('retention: the LAST PASS FAILED and nothing has pruned since ('
                    .($lastError['exception'] ?? 'error').': '.($lastError['error'] ?? '')
                    .' at '.($lastError['at'] ?? '?').'). Check DB/file permissions and disk space; if traffic has since resumed, watch the log for a clean `retention pass` (the marker clears itself on the next success).');
            }
        } catch (Throwable $e) {
            yield Finding::unvalidated('retention: could not read the last-failure marker ('.$e->getMessage().') — the cache backend the retention gate depends on may be unreachable.');
        }
    }

    /**
     * Whether any indication is available HERE that the receiver's PHP can end a request
     * before running terminating callbacks — deliberately not whether it can.
     *
     * IT DOES NOT ANSWER THE QUESTION THE CALLER ASKS, and the name now says so. The
     * previous name (`receiverSapiFinishesEarly()`) claimed it did, and its docblock said
     * this probed "the fpm binary's own module list" — no module list has ever been read,
     * by this method or any other. The subject that matters is the RECEIVER's process,
     * and `bridge:check` is a console command that never runs as it: the same subject
     * discriminator DL-260 applied to the channel token and DL-254 to the board-tools
     * resolver.
     *
     * Two limbs, and they are not the same kind of evidence:
     *
     *  - `function_exists()` is AUTHORITATIVE WHEN TRUE, because the process asking is
     *    the process answering. It is unreachable today — every caller is `bridge:check`
     *    and the CLI SAPI never defines the function — and it is kept precisely because
     *    it is the only limb whose subject is right: a check ever run inside the
     *    receiver's own SAPI is answered correctly by it and wrongly by the one below.
     *  - `ExecutableFinder` only asks whether a php-fpm binary is FINDABLE on
     *    `getenv('PATH')`. That is an indicator about this process's environment, never a
     *    fact about the receiver, and it is weak in BOTH directions: a healthy FPM
     *    install can keep the binary in `/usr/sbin`, off a non-root login PATH (measured
     *    on the reference host — it serves the receiver under FPM and still finds nothing
     *    from a shell whose PATH omits `/usr/sbin`), and a findable binary does not mean
     *    the receiver is served by it.
     *
     * ExecutableFinder resolves from `getenv('PATH')` only, which is what makes this
     * host read pinnable from a test — the golden harness's `PinnedHost` (NAMED, never
     * imported: app code must not depend on the suite) points PATH at a fixture bin dir
     * to control the answer.
     */
    private function earlyFinishIndicated(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            return true;   // running under FPM already
        }
        foreach (['php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, 'php-fpm'] as $bin) {
            $path = (new ExecutableFinder)->find($bin);
            if ($path !== null) {
                return true;
            }
        }

        return false;
    }
}
