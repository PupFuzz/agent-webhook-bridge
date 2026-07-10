<?php

namespace App\Bridge\Writeback;

use App\Bridge\Support\SecretFile;
use App\Bridge\Support\TokenPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Read-only GitHub PR-state client for the reconciler (bridge:reconcile, DL-183).
 * The event-driven writeback derives a card's stage from the webhook body; the
 * reconciler instead recomputes it from GitHub GROUND TRUTH — GET the PR and read
 * its current state/merged/base. That closes RC-B (a webhook dropped during a
 * bridge outage is never re-delivered — GitHub fires each once).
 *
 * Uses the general per-provider token convention (<secret_dir>/github/token), NOT
 * the dedicated least-privilege writeback token — this is a github credential, not
 * a kanban one, and the kanban-board repo is private so a `repo`-scoped read token
 * is required in practice. The 0600 posture (DL-010) is enforced on read.
 *
 * Verb-only + throws on non-2xx: the caller (ReconcileCommand) decides that a
 * per-card 4xx/5xx is warn + skip, never abort the whole run.
 */
final class GitHubReadClient
{
    public const API_BASE = 'https://api.github.com';

    /** Kept under a human-interactive command's patience; a slow GitHub is skipped per card. */
    public const TIMEOUT_SECONDS = 15;

    public function __construct(private string $token) {}

    /** The <secret_dir>/github/token path (the general per-provider token convention). */
    public static function tokenPath(): string
    {
        return TokenPath::for((string) config('bridge.secret_dir'), 'github');
    }

    /**
     * Build from the configured github token, or null when it is absent/blank (the
     * caller reports that reconcile cannot run). Throws InsecureSecretPermsException
     * on a group/world-readable token (DL-010 fail-closed).
     */
    public static function fromConfig(): ?self
    {
        $token = SecretFile::read(self::tokenPath());   // throws on insecure perms

        return $token === null ? null : new self($token);
    }

    /**
     * One-shot auth/scope probe for a repo (`GET /repos/{repo}`). Throws
     * RequestException on any non-2xx — used at startup to fail LOUDLY when the
     * token can't see a private repo (404), is expired/revoked (401), or lacks
     * scope (403), instead of every per-card getPull silently 404-ing and the run
     * exiting 0 (the degraded-read-must-be-loud posture, DL-026 lineage).
     */
    public function probeRepo(string $repo): void
    {
        $this->http()->get(self::API_BASE."/repos/{$repo}")->throw();
    }

    /**
     * The PR's ground-truth state. Throws RequestException on any non-2xx (a
     * deleted PR 404s — the caller warns + skips that card once the repo probe
     * has confirmed the token CAN see the repo).
     *
     * @return array{state: string, merged: bool, base_ref: string, html_url: string}
     */
    public function getPull(string $repo, int $number): array
    {
        $pr = $this->http()->get(self::API_BASE."/repos/{$repo}/pulls/{$number}")->throw()->json();
        $pr = is_array($pr) ? $pr : [];
        $base = is_array($pr['base'] ?? null) ? ($pr['base']['ref'] ?? '') : '';

        return [
            'state' => is_string($pr['state'] ?? null) ? $pr['state'] : '',
            'merged' => ($pr['merged'] ?? false) === true,
            'base_ref' => is_string($base) ? $base : '',
            'html_url' => is_string($pr['html_url'] ?? null) ? $pr['html_url'] : '',
        ];
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'agent-webhook-bridge',   // GitHub rejects a UA-less request
            ])
            ->timeout(self::TIMEOUT_SECONDS);
    }
}
