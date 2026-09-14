<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEvent extends Model
{
    protected $fillable = [
        'delivery_id',
        'provider',
        'scope_id',
        'event_type',
        'actor_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    /**
     * @return HasMany<AgentDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(AgentDispatch::class);
    }

    /**
     * Rows for exactly this provider+scope spelling — never another spelling the DB collation treats as equal.
     *
     * MariaDB's default `scope_id` collation is `utf8mb4_unicode_ci`: `where('scope_id', $scope)` alone credits
     * `Owner/Repo` with `owner/repo`'s rows there, though the dispatcher and receiver both match a scope byte for
     * byte, so a row under the other spelling wakes nothing. SQLite's `=` is already byte-exact and needs no extra
     * predicate.
     *
     * ⭐ HOISTED TO ONE PRIMITIVE (DL-382 R1 finding 2, canon #5): `EventConsumerReconciler::arrivals()` and
     * `GitHubDeliveryHistoryCheck::historyOf()` each re-derived this exact-spelling comparison independently —
     * one at the SQL layer with no defense, one with a PHP post-filter after the query. A third reader would have
     * re-derived it a third way. Both now read this, and the PHP post-filter is gone: the SQL predicate makes it
     * redundant.
     *
     * ⭐ THE BYTE COMPARISON CASTS THE COLUMN, NEVER THE PARAMETER, AND NAMES NO CHARSET. `scope_id = ? COLLATE
     * utf8mb4_bin` (R1) was refused at PREPARE time by MariaDB 10.6 — error 1253, *COLLATION 'utf8mb4_bin' is not valid
     * for CHARACTER SET 'binary'* — because Laravel prepares server-side (`PDO::ATTR_EMULATE_PREPARES` is false) and
     * 10.6 types an unbound `?` as `binary` when it resolves the COLLATE clause; 10.11 and 11.8 accept it. Measured on
     * each. `CAST(scope_id AS BINARY) = ?` applies no operator to the marker, so there is nothing to type at prepare,
     * and it names no charset: a named `utf8mb4_bin` is refused outright on a latin1 or utf8mb3 connection, on 10.6
     * and 11.8 alike, while the cast matched exactly on those connections and on utf8mb4. The plain
     * `where('scope_id', …)` stays first so the `(provider, scope_id)` index still narrows the read.
     *
     * ⚠ ONLY A MARIADB RUN CAN DISCRIMINATE A REGRESSION HERE. SQLite's `=` is already byte-exact, so a test asserting
     * a case-variant scope is not credited passes on SQLite whether or not the predicate below is even applied — it is
     * exercised for real only where the collation is case-insensitive, which is the CI MariaDB matrix.
     *
     * @param  Builder<WebhookEvent>  $query
     * @return Builder<WebhookEvent>
     */
    public function scopeForExactScope(Builder $query, string $provider, string $scope): Builder
    {
        $query->where('provider', $provider)->where('scope_id', $scope);

        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('CAST(scope_id AS BINARY) = ?', [$scope]);
        }

        return $query;
    }
}
