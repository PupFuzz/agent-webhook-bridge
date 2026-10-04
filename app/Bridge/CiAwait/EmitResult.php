<?php

namespace App\Bridge\CiAwait;

/** What {@see CiAwaitService} claiming one await and emitting its event came to. */
enum EmitResult
{
    /** This call's delete removed the row and the inbox line was written. */
    case Emitted;

    /** Another call claimed the row first; it emitted there. */
    case ClaimedElsewhere;

    /** The inbox line could not be written: the claim rolled back, the row is kept and marked. */
    case Failed;
}
