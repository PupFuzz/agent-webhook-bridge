<?php

namespace Tests\Support\CheckGolden;

use App\Bridge\Retention\RetentionGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Pins the ambient host inputs `bridge:check` reads, so a golden capture answers for
 * the FIXTURE and not for the box it ran on (DL-242 stage 0).
 *
 * This class is the stage-0 falsifier's verdict in code. Byte-identical capture IS
 * achievable, but NOT for a naive capture: `bridge:check` reads its host directly,
 * and at least one of those reads is VERDICT-BEARING. With `$COORD_CONFIG` unset the
 * terminal-agreement leg prints *"… $COORD_CONFIG is not set …"*; with it set to a
 * path that does not parse it prints *"… the coordination config at <path> is absent,
 * unreadable, or malformed …"*. Same severity, different diagnosis, different operator
 * instruction — so the plan's "never normalize anything that encodes a verdict" rule
 * forbids normalizing it away. It is pinned instead.
 *
 * WHAT IS PINNED, and why each one is here rather than guessed at:
 *
 *   1. `PATH` — `RetentionPostureCheck::receiverSapiFinishesEarly()` asks
 *      Symfony's ExecutableFinder for a `php-fpm<major>.<minor>` or `php-fpm` binary.
 *      ExecutableFinder resolves ONLY from `getenv('PATH')` plus an `exec('command -v')`
 *      fallback that inherits the same PATH, so pointing PATH at a fixture bin dir
 *      controls the answer completely. Reached on the DEFAULT path (retention is on by
 *      default), so it affects nearly every fixture — a host without php-fpm emits an
 *      extra retention warn line. Whether CI's runner has php-fpm was the stage-0 NAMED
 *      GAP; pinning closes it rather than assuming hosts agree.
 *   2. `XDG_RUNTIME_DIR` — interpolated into the HTTP channel bind-failure marker path.
 *   3. `COORD_CONFIG` — the proven-divergent one above, read by
 *      `CheckCommand::checkCoordTerminalAgreement()`.
 *   4. `GH_TOKEN` — `GitHubTokenResolver` falls back to it (resolver L204), so an
 *      operator shell that exports it silently satisfies a token probe that must fail
 *      on a fixture with no token.
 *
 * (4) is NOT in the falsifier's six. The falsifier enumerated `CheckCommand.php`; this
 * one is reached transitively through `GitHubTokenResolver`, together with the
 * `providers.github.credential_helper` config neutralization {@see GoldenInstall}
 * performs. An enumeration bounded by one file is bounded by that file.
 *
 * The remaining host inputs from that enumeration are handled elsewhere because they
 * are not env: `posix_getuid()` (the channel-socket uid hint) and absolute paths are
 * NORMALIZED by {@see GoldenCapture} (neither encodes a verdict — the verdict is "not
 * writable" / "absent", which survives), and the retention last-failure marker read by
 * `RetentionPostureCheck` is ambient CACHE state, cleared by {@see resetAmbientState()} —
 * NOT by {@see apply()}, and the difference is load-bearing rather than cosmetic. See that
 * method for why; clearing it from `apply()` silently voided the one fixture that sets it.
 *
 * ONE MORE IS PINNED BEHIND A SEAM RATHER THAN HERE: whether anything is LISTENING at a
 * channel endpoint. It is a live connect, not an env read, so it is pinned by binding
 * {@see GoldenChannelEnvironment} for every fixture (DL-242 stage 5b) — verdict-bearing
 * in the same way `$COORD_CONFIG` is, and unreachable by any fixture today, which is
 * precisely why it needed pinning before one reaches it.
 *
 * PINS NAME THE CONSTRUCT, NEVER A `CheckCommand` LINE NUMBER — a pin asserts a HOST FACT,
 * which does not move when the code reading it does (pin 1's reader has already migrated
 * out of `CheckCommand` and the pin is unchanged). Rule and evidence:
 * `docs/CHECK-REGISTRY-PLAN.md` § Stage 2 result.
 */
final class PinnedHost
{
    private const PINNED = ['PATH', 'XDG_RUNTIME_DIR', 'COORD_CONFIG', 'GH_TOKEN'];

    /** @var array<string, string|false> */
    private array $saved = [];

    private bool $applied = false;

    public function __construct(private readonly string $root) {}

    /**
     * @param  bool  $fpmPresent  Whether the receiver's SAPI can finish early — i.e.
     *                            whether a php-fpm binary is on PATH. Both values are
     *                            reachable on any host because the binary is a stub.
     * @param  string|null  $coordConfig  Value for `$COORD_CONFIG`; null leaves it UNSET
     *                                    (a distinct, verdict-bearing state, not a default).
     */
    public function apply(bool $fpmPresent = false, ?string $coordConfig = null): void
    {
        foreach (self::PINNED as $var) {
            $this->saved[$var] = getenv($var);
        }
        $this->applied = true;

        $bin = $this->root.'/bin';
        File::ensureDirectoryExists($bin);
        // The name receiverSapiFinishesEarly() looks for FIRST, built from the same
        // constants it uses — so the stub matches whatever PHP is running the suite.
        $fpm = $bin.'/php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if ($fpmPresent) {
            File::put($fpm, "#!/bin/sh\nexit 0\n");
            chmod($fpm, 0o755);
        } elseif (is_file($fpm)) {
            File::delete($fpm);
        }

        // PATH is the fixture bin dir and NOTHING else: any real php-fpm on the host
        // must be unreachable, or `fpmPresent: false` would be a lie on this box and
        // true on CI. Subprocesses the command spawns resolve their binary absolutely
        // (ClassifierResolver uses PHP_BINARY), so an empty-ish PATH does not break them.
        putenv('PATH='.$bin);

        $xdg = $this->root.'/xdg';
        File::ensureDirectoryExists($xdg);
        putenv('XDG_RUNTIME_DIR='.$xdg);

        $coordConfig === null ? putenv('COORD_CONFIG') : putenv('COORD_CONFIG='.$coordConfig);
        putenv('GH_TOKEN');
    }

    /**
     * Clear the ambient CACHE state `bridge:check` reads (the retention last-failure
     * marker), so a fixture answers for itself and not for a prior test in the process.
     *
     * SEPARATE FROM {@see apply()}, AND CALLED BEFORE THE FIXTURE IS BUILT — that ordering
     * is the whole point. The clear used to live at the end of `apply()`, which runs AFTER
     * the builder (the builder is what tells `apply()` which pins to set), so it erased the
     * marker `retention-last-pass-failed` had just written: the fixture captured the
     * healthy output and sat byte-identical to `minimal` from the day it was committed
     * (card#5552). The cure is to reset ambient state ahead of the builder, never to make
     * the fixture re-set it afterwards — that would hide the ordering for the next fixture
     * that needs ambient state.
     */
    public function resetAmbientState(): void
    {
        Cache::forget(RetentionGate::ERROR_KEY);
    }

    public function restore(): void
    {
        if (! $this->applied) {
            return;
        }
        foreach ($this->saved as $var => $value) {
            $value === false ? putenv($var) : putenv("{$var}={$value}");
        }
        $this->applied = false;
    }

    /**
     * Set every pinned variable to a hostile value. Used ONLY by the immunity test, to
     * establish that {@see apply()} overrides ambient state rather than inheriting it —
     * without a perturbation there is nothing for immunity to be immune to.
     */
    public function perturbAmbient(): void
    {
        putenv('PATH=/usr/sbin:/usr/bin:/bin:/sbin');
        putenv('XDG_RUNTIME_DIR=/run/user/999999');
        putenv('COORD_CONFIG=/nonexistent/ambient-coordination.config.json');
        putenv('GH_TOKEN=ghp_ambient_operator_shell_token');   // gitleaks:allow — test fixture
    }
}
