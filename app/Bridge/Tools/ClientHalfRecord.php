<?php

namespace App\Bridge\Tools;

use App\Models\BoardToolsClientCall;
use Carbon\CarbonInterface;

/**
 * One agent's last successful board-tools call, as {@see ClientHalfLedger}'s readers hand it
 * out — the row's facts, resolved, with no Eloquent model behind them.
 *
 * WHY A RECORD RATHER THAN THE MODEL. The enum cast on {@see BoardToolsClientCall} is applied
 * LAZILY, on attribute access, so handing a caller the model hands them a `ValueError` that
 * can fire anywhere they choose to read — including outside whatever fail-soft envelope they
 * built. Resolving every field at the reader means the throw happens at the READER CALL,
 * which is a place each caller can wrap on purpose.
 *
 * ⛔ `lastSuccessAt` IS TYPED `CarbonInterface`, NOT `\DateTimeInterface`, and the difference
 * is load-bearing rather than stylistic: the reading check computes an age with
 * `diffInSeconds()`, which `\DateTimeInterface` does not declare. The looser type compiles
 * and reds under static analysis at the call site instead — a type that is wrong where it is
 * USED rather than where it is written.
 *
 * ⚑ `transport` IS A FIELD BECAUSE THE OPERATOR LINES PRINT IT — `over ssh`, `over http` —
 * and it is the DOOR, never the provenance. Printing a provenance where a transport is meant
 * would put an internal measurement into a sentence about which front door served the call.
 *
 * ⛔ `provenance` IS NULLABLE AND NULL IS A REAL THIRD STATE (DL-316): a row written before
 * the column existed, carrying no measurement in either direction. {@see CallProvenance} has
 * no `Unknown` case precisely so the two cannot be collapsed, and both available backfills
 * would be lies — so the absence is carried through to the reader rather than resolved here.
 *
 * ⛔ `clientVersion` IS NULLABLE FOR THREE DIFFERENT REASONS AND THEY ARE DELIBERATELY NOT
 * KEPT APART (DL-364): a client older than {@see ClientVersion::FIRST_REPORTING_SNAPSHOT},
 * a caller that is not a channel server at all (`--self-cert`, a hand-run
 * `bridge:tools-call`), and a value {@see ClientVersion} refused. All three are "this call
 * reported no version", the reading check says exactly that, and no state here claims more.
 */
final readonly class ClientHalfRecord
{
    public function __construct(
        public string $agent,
        public CarbonInterface $lastSuccessAt,
        public string $transport,
        public ?CallProvenance $provenance,
        public ?string $clientVersion,
    ) {}
}
