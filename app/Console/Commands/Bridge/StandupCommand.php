<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\Standup\StandupConfig;
use App\Bridge\Standup\StandupGate;
use App\Bridge\Standup\StandupService;

/**
 * The PM standup digest (DL-306) — the manual entry point to the same service the
 * receiver's {@see StandupGate} drives, and the way to SEE a digest
 * without waiting for a delivery. `bridge:prune` stands in exactly this relation to
 * retention.
 *
 * `--dry-run` builds the snapshot and prints it as JSON, pushing nothing. That is the
 * surface an operator uses to answer the only question worth asking about a report: is
 * every number in it one the bridge actually measured? Fields the bridge cannot source
 * are ABSENT from that JSON — if a key is missing, that is the answer, not an omission.
 *
 * It runs the digest even when `bridge.standup.enabled` is false, and reports that it is
 * doing so: the flag governs the automatic pass, and refusing an explicit operator
 * invocation on it would leave no way to inspect a digest before switching it on.
 */
class StandupCommand extends BridgeCommand
{
    protected $signature = 'bridge:standup {--dry-run : build the digest and print it, push nothing}';

    protected $description = 'Build (and push) the PM standup digest of what the bridge can derive';

    public function __construct(private readonly StandupService $standup)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        return $this->guardDatabase($this->handleGuarded(...));
    }

    private function handleGuarded(): int
    {
        $cfg = StandupConfig::fromConfig();
        $dry = (bool) $this->option('dry-run');

        if (! $dry && ! $cfg->isUsable()) {
            $this->error('standup: '.$cfg->problem);

            return self::FAILURE;
        }

        $digest = $this->standup->build();

        if ($dry) {
            $this->line((string) json_encode($digest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (! $cfg->isUsable()) {
                $this->warn('standup: built but NOT pushable — '.$cfg->problem);
            } elseif (! $cfg->enabled) {
                $this->warn('standup: BRIDGE_STANDUP_ENABLED is false, so no delivery will push this automatically ('.$cfg->summary().')');
            }

            return self::SUCCESS;
        }

        $this->standup->push($digest, (string) $cfg->agent);
        $this->info($digest->summary().' → pushed to '.$cfg->agent);

        if (! $cfg->enabled) {
            $this->warn('standup: BRIDGE_STANDUP_ENABLED is false — this run pushed, but no delivery will.');
        }

        return self::SUCCESS;
    }
}
