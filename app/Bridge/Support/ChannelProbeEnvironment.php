<?php

namespace App\Bridge\Support;

/**
 * The one host fact the `bridge:check` channel-transport legs read that a test cannot
 * construct hermetically: whether anything is listening at a channel endpoint
 * (DL-242 stage 5b).
 *
 * THE SEAM IS DELIBERATELY THIS NARROW. Everything else those legs read IS
 * constructible — filesystem state from a temp dir (the golden harness already builds
 * whole install dirs that way) and `XDG_RUNTIME_DIR` from `putenv` (the golden harness
 * pins it). Only the connect needs a seam, for two reasons: a live-vs-dead endpoint
 * cannot be bound deterministically in-process, and the transport's error text reaches
 * the operator message while being platform-dependent — a host input in exactly the
 * sense the golden harness's host pinning exists to eliminate. Widening this interface
 * to wrap `is_dir()` would buy no measurement.
 */
interface ChannelProbeEnvironment
{
    /**
     * Connect to $dsn (`unix://<path>` or `tcp://<host>:<port>`) and close immediately —
     * the question is only whether something answers.
     *
     * @return array{connected: bool, error: string} `error` is the transport's own text,
     *                                               '' when it gave none. It is
     *                                               meaningless when `connected`.
     */
    public function probe(string $dsn): array;
}
