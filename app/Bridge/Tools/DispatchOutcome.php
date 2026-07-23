<?php

namespace App\Bridge\Tools;

/**
 * The transport-neutral result of a {@see BoardToolDispatcher::dispatch} call
 * (Finding A, card 4952) — the shape both board-tools front doors map from:
 *  - the HTTP controller renders {@see body()} as a JsonResponse with {@see $status} verbatim;
 *  - `bridge:tools-call` writes {@see body()} as the single JSON stdout envelope and
 *    exits with {@see exitCode()}.
 *
 * `status` is the SAME status-class the controller has always returned (200 ok,
 * 422 bad tool/args/refusal, 502 upstream, 503 writeback-unavailable); the exit
 * code derives from it so the ssh client can tell a caller-fixable 4xx (exit 1)
 * from a service-fault 5xx (exit 2). The response BODY is transport-native-free:
 * both doors serialize the identical `{ok, tool, result}` / `{ok, error}` shape
 * from {@see body()} (DR4 body-shape parity), so the .mjs relay yields the same
 * MCP content whichever door served it.
 */
final class DispatchOutcome
{
    /**
     * @param  array<string, mixed>|null  $result
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly ?string $toolName,
        public readonly ?array $result,
        public readonly ?string $error,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function success(string $toolName, array $result): self
    {
        return new self(true, 200, $toolName, $result, null);
    }

    public static function failure(int $status, string $error): self
    {
        return new self(false, $status, null, null, $error);
    }

    /**
     * The response body — byte-identical between the HTTP door
     * (`response()->json(body(), status)`) and the ssh stdout envelope. The ONLY
     * serializer of a board-tools result; a divergence here would split the two
     * transports' MCP content.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->ok
            ? ['ok' => true, 'tool' => $this->toolName, 'result' => $this->result]
            : ['ok' => false, 'error' => $this->error];
    }

    /**
     * The ssh-client exit code: 0 iff ok, 2 for a 5xx service fault (the client
     * may retry the bridge), 1 for any other (4xx-class) caller-fixable failure.
     */
    public function exitCode(): int
    {
        if ($this->ok) {
            return 0;
        }

        return $this->status >= 500 ? 2 : 1;
    }
}
