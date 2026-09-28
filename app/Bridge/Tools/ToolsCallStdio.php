<?php

namespace App\Bridge\Tools;

/**
 * The three standard streams `bridge:tools-call` (the ssh-forced-command board-tools
 * front door, card 4952) reads and writes, behind a tiny seam so a test can capture
 * stdout WITHOUT the real fd 1 — which is exactly the channel sshd hands back to the
 * remote caller as the tool result, so its purity is load-bearing.
 *
 * The default resolves the PHP CLI stream constants. A test binds a fake into the
 * container (seeded stdin, in-memory capture for stdout/stderr) and asserts stdout
 * carries nothing but the one JSON envelope.
 */
class ToolsCallStdio
{
    /**
     * The most STDIN `bridge:tools-call` reads: a booted Laravel process must not buffer unbounded
     * input. It is the smallest request-body cap of the two board-tools doors (the HTTP routes have
     * none of their own), so a request contract that must fit both doors derives from it — the
     * client-update door's `client_report` does, in
     * `App\Bridge\ClientUpdate\SeatClientLedger::MAX_REPORT_BYTES` (a plain name: Pint turns a
     * docblock FQCN into an import, and this class need not depend on that one).
     */
    public const MAX_STDIN_BYTES = 65536;   // 64 KiB

    /**
     * @return resource
     */
    public function in()
    {
        return STDIN;
    }

    /**
     * @return resource
     */
    public function out()
    {
        return STDOUT;
    }

    /**
     * @return resource
     */
    public function err()
    {
        return STDERR;
    }
}
