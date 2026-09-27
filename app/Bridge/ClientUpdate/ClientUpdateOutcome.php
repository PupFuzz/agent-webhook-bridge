<?php

namespace App\Bridge\ClientUpdate;

use App\Bridge\Tools\DispatchOutcome;

/**
 * What the client-update door answers, for both transports: the HTTP route renders {@see $body}
 * with {@see $status}, and `bridge:tools-call` writes {@see $body} as its one stdout envelope and
 * exits with {@see exitCode()} — the same exit rule as a board-tools call.
 *
 * Success is `{ok: true, op, …}`; failure is `{ok: false, error}`, the board-tools failure shape.
 */
final class ClientUpdateOutcome
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function __construct(public readonly int $status, public readonly array $body) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function success(string $op, array $fields): self
    {
        return new self(200, ['ok' => true, 'op' => $op] + $fields);
    }

    public static function failure(int $status, string $error): self
    {
        return new self($status, ['ok' => false, 'error' => $error]);
    }

    public function exitCode(): int
    {
        return DispatchOutcome::exitCodeFor($this->status === 200, $this->status);
    }
}
