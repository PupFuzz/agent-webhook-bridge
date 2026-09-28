<?php

namespace App\Models;

use App\Bridge\ClientUpdate\SeatClientLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry of the append-only client-update event log (card#10567 B4): a seat's install-log line
 * received by `client_report`, or a bridge-side event (`install_id` = {@see SeatClientLedger::BRIDGE_INSTALL_ID}).
 *
 * ⛔ Nothing updates or deletes a row — `SeatClientEventsNoWriterTest` checks app/ for it.
 *
 * @property string $agent
 * @property string $install_id
 * @property int $seq
 * @property string $action
 * @property ?string $from_bridge_release
 * @property ?string $to_bridge_release
 * @property ?string $client_version
 * @property ?string $pack_sha256
 * @property ?string $files_json_sha256
 * @property ?string $source
 * @property ?string $actor
 * @property ?string $result
 * @property ?string $reason
 * @property ?string $launch_id
 * @property ?string $prev_sha256
 * @property ?string $line_sha256
 * @property ?string $occurred_at
 * @property Carbon $received_at
 */
class SeatClientEvent extends Model
{
    /** The `reason` column's width in characters (its migration); an approval's `--reason` is held to it. */
    public const REASON_MAX_CHARS = 500;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'seq' => 'integer',
        'received_at' => 'datetime',
    ];
}
