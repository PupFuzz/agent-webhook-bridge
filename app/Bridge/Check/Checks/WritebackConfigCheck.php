<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Support\Finding;

/**
 * The `writeback.json` load report, migrated out of `CheckCommand::handle()`
 * (DL-242 stage 3a).
 *
 * It runs only inside the surviving inline envelope that loads the file, so reaching
 * it means the load SUCCEEDED — a malformed file throws and is reported by that
 * envelope's catch instead. The mapping count is therefore a statement about a file
 * that parsed, which is why it is `ok` rather than merely informational.
 *
 * A null config with the file present is a real state (`load()` returns null for a
 * file carrying no mappings), and it prints `0 repo mapping(s)` exactly as it does
 * today — the one line in this cluster that fires unconditionally, and the anchor an
 * operator uses to tell "writeback off" from "writeback on with nothing mapped".
 */
final class WritebackConfigCheck implements Check
{
    public function id(): string
    {
        return 'writeback.config';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $count = $ctx->writeback !== null ? count($ctx->writeback->mappings) : 0;

        yield Finding::ok("writeback.json: {$count} repo mapping(s)");
    }
}
