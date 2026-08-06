<?php

namespace Tests\Support\CheckGolden;

use App\Bridge\Support\ChannelProbeEnvironment;

/**
 * Stand up a throwaway `bridge:check` install with the FULL pin set applied, in the one
 * order that works (DL-242 stage 0).
 *
 * WHY THIS IS A SHARED PRIMITIVE AND NOT A PER-TEST SEQUENCE. The pins are not a
 * checklist a test can be trusted to reproduce: they are four inputs {@see PinnedHost}
 * enumerates plus the channel-probe seam bound here, and a test that copies the sequence
 * and drops ONE of them does not fail — it silently answers for the box it ran on. The
 * first second caller did exactly that, omitting the channel-probe binding, and nothing
 * could have noticed: no fixture it ran reaches a probe, so the omission would have sat
 * there until some later install shape did. One boot site is what makes "every pin, every
 * run" a property of the code rather than of the author's memory.
 *
 * THE ORDER IS LOAD-BEARING, and each step is here rather than in the caller for a
 * reason the caller cannot see:
 *   - the channel seam is bound BEFORE the builder runs, so a fixture that ever wants a
 *     live endpoint overrides it deliberately instead of inheriting the box;
 *   - {@see PinnedHost::resetAmbientState()} runs BEFORE the builder too, because a
 *     fixture that WANTS ambient cache state sets it in the builder and a reset after
 *     that erases it (card#5552 — one fixture sat byte-identical to its own baseline from
 *     the day it was committed);
 *   - {@see PinnedHost::apply()} runs AFTER, because the builder is what decides two of
 *     its arguments.
 *
 * The builder OWNS calling {@see GoldenInstall::boot()} — the `bridge.*` baseline is a
 * fixture's own declaration, and a fixture that overrides a key must be able to do it
 * after the baseline lands. It may return the spec array to pass `fpm` / `coordConfig` /
 * `args` back; returning nothing takes the defaults.
 */
trait BootsGoldenInstall
{
    protected GoldenInstall $install;

    protected PinnedHost $host;

    /**
     * @param  callable(GoldenInstall): mixed  $build  the install shape; returns the spec
     *                                                 array (or nothing, for the defaults)
     * @param  bool  $perturb  set every pinned variable HOSTILE first — for the immunity
     *                         control only, which has nothing to be immune to without it
     * @return array{args: array<string, mixed>, fpm: bool, coordConfig: string|null}
     */
    protected function bootGoldenInstall(string $name, callable $build, bool $perturb = false): array
    {
        // TEAR THE PREVIOUS ONE DOWN FIRST, so a nested boot is unrepresentable rather than
        // something each test has to remember. {@see PinnedHost} snapshots the ambient values
        // in `apply()`, not in its constructor, so a second boot inside one test method —
        // several callers walk a list of shapes — reaches `apply()` while the first host is
        // still applied and saves the PINNED `PATH` as if it were ambient. `tearDown()` then
        // "restores" the process to a fixture bin dir that no longer exists, and every later
        // test in the process that shells out runs with it.
        $this->tearDownGoldenInstall();

        $this->install = new GoldenInstall($name);
        $this->host = new PinnedHost($this->install->path());

        $this->app->instance(ChannelProbeEnvironment::class, new GoldenChannelEnvironment);
        $this->host->resetAmbientState();
        if ($perturb) {
            $this->host->perturbAmbient();
        }

        $spec = $build($this->install);
        $spec = (is_array($spec) ? $spec : []) + ['args' => [], 'fpm' => false, 'coordConfig' => null];
        $this->host->apply(fpmPresent: $spec['fpm'], coordConfig: $spec['coordConfig']);

        return $spec;
    }

    /**
     * Restore the host and delete the install. Safe to call when neither exists: a test
     * that only READS golden files never boots one, and an un-restored PATH would leak
     * into every later test in the process.
     */
    protected function tearDownGoldenInstall(): void
    {
        if (isset($this->host)) {
            $this->host->restore();
        }
        if (isset($this->install)) {
            $this->install->destroy();
        }
        unset($this->host, $this->install);
    }
}
