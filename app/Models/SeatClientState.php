<?php

namespace App\Models;

use App\Bridge\ClientUpdate\SeatClientLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One board-tools agent's reported channel-server client state (card#10567 B4). Every column is
 * the seat's own report; {@see SeatClientLedger} owns every write.
 *
 * @property string $agent
 * @property ?string $install_id
 * @property ?Carbon $last_call_at
 * @property ?string $last_call_client_version
 * @property ?string $last_call_launch_id
 * @property ?string $running_launch_id
 * @property ?string $running_bridge_release
 * @property ?string $running_client_version
 * @property ?Carbon $running_launch_first_seen_at
 * @property ?Carbon $running_seen_at
 * @property ?Carbon $last_exempt_call_at
 * @property ?string $last_exempt_caller
 * @property ?string $installed_bridge_release
 * @property ?string $installed_client_version
 * @property ?string $installed_files_json_sha256
 * @property ?string $last_launch_id
 * @property ?string $last_launch_result
 * @property ?string $last_launch_error
 * @property ?Carbon $last_launch_first_reported_at
 * @property ?Carbon $last_report_at
 * @property ?int $log_seq
 * @property ?string $log_head_sha256
 * @property bool $log_discontinuity
 * @property ?string $log_discontinuity_reason
 */
class SeatClientState extends Model
{
    protected $guarded = ['id'];

    /** A row born in memory reads its chain as unbroken, as the column default does. */
    protected $attributes = ['log_discontinuity' => false];

    protected $casts = [
        'last_call_at' => 'datetime',
        'running_launch_first_seen_at' => 'datetime',
        'running_seen_at' => 'datetime',
        'last_exempt_call_at' => 'datetime',
        'last_launch_first_reported_at' => 'datetime',
        'last_report_at' => 'datetime',
        'log_seq' => 'integer',
        'log_discontinuity' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
