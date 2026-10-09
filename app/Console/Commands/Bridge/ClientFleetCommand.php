<?php

namespace App\Console\Commands\Bridge;

use App\Bridge\ClientUpdate\ClientFleet;
use App\Bridge\ClientUpdate\ClientPackStore;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\HumanAge;
use App\Bridge\Support\RedactedErrorText;
use App\Bridge\Support\SubscriptionRegistry;
use App\Bridge\Support\UntrustedText;
use App\Bridge\Tools\ClientCapabilities;
use Illuminate\Support\Carbon;

/**
 * `bridge:client-fleet` — what every board-tools seat reported about its channel-server client, and
 * the state that report derives (card#10567 B4). {@see ClientFleet} owns the derivation and the
 * `warn` decision; this prints them. `--json` prints {@see ClientFleet::toArray()}, the same
 * document the `client_fleet` op returns.
 *
 * EXIT: 0 read (whatever the seats' states) · 1 the fleet ledger could not be read · 2 the agent
 * YAMLs could not be loaded.
 */
class ClientFleetCommand extends BridgeCommand
{
    protected $signature = 'bridge:client-fleet {--json : print the fleet document instead of lines}';

    protected $description = 'Show each board-tools seat\'s reported channel-server client, its update state and its capability gap (card#10567)';

    public function handle(ClientPackStore $store): int
    {
        try {
            $configs = (new SubscriptionRegistry((string) config('bridge.config_dir')))->agentConfigs();
        } catch (ConfigException $e) {
            $this->error('bridge:client-fleet: the agent YAMLs could not be loaded ('.RedactedErrorText::of($e).'), so which seats to show is unknown.');

            return 2;
        }

        return $this->guardDatabase(function () use ($configs, $store): int {
            $now = Carbon::now();
            $fleet = ClientFleet::read($configs, $store, $now);
            if ($this->option('json')) {
                $this->line((string) json_encode($fleet->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            $this->printFleet($fleet, $now);

            return self::SUCCESS;
        });
    }

    private function printFleet(ClientFleet $fleet, Carbon $now): void
    {
        $p = $fleet->published;
        if ($fleet->publishedError !== null) {
            $this->line('bridge:client-fleet: the published client pack record cannot be read ('.UntrustedText::forOperator($fleet->publishedError).'), so no seat is compared against a published release.');
        } elseif ($p === null) {
            $this->line('bridge:client-fleet: this bridge publishes no client pack yet (`php artisan bridge:client-pack:install`), so no seat can be on the update path.');
        } else {
            $this->line("bridge:client-fleet: published client pack: release {$p->bridgeRelease} (client {$p->clientVersion}, content ".substr($p->filesJsonSha256, 0, 12)."), published {$p->publishedAt}.");
        }

        $doc = $fleet->toArray();
        if ($doc['seats'] === []) {
            $this->line('bridge:client-fleet: no agent has an enabled board_tools block, so there is no fleet to show.');

            return;
        }
        $warned = 0;
        foreach ($doc['seats'] as $seat) {
            if ($seat['warn']) {
                $warned++;
            }
            $this->line("seat {$seat['agent']} [{$seat['transport']}] — {$seat['label']}: {$seat['reason']}.");
            foreach ($seat['caveats'] as $caveat) {
                $this->line("    ⚠ {$caveat}.");
            }
            $this->line('    '.implode(' · ', [
                'running: '.self::release($seat['running']),
                'installed: '.self::release($seat['installed']),
                'last seen: '.($seat['last_seen'] === null ? 'not since this bridge started its fleet ledger' : HumanAge::floored((int) Carbon::parse($seat['last_seen'])->diffInSeconds($now, true)).' ago'),
                'approval: '.($seat['approval_required'] ? 'required' : 'not required'),
                'capability gap'.($seat['capability_gap_against'] !== null ? " vs this bridge's client {$seat['capability_gap_against']}" : '').': '.self::gap($seat['capability_gap']),
            ]));
        }
        $spread = [];
        foreach ($doc['spread'] as $key => $n) {
            $spread[] = "{$key} ×{$n}";
        }
        $this->line(count($doc['seats']).' seat(s) — '.implode(', ', $spread).'; '.($warned === 0 ? 'none needs you.' : "{$warned} need(s) you (bridge:check warns on the same seats)."));
    }

    /**
     * @param  array<string, mixed>|null  $r
     */
    private static function release(?array $r): string
    {
        if ($r === null) {
            return 'not reported';
        }

        return "release {$r['bridge_release']}".($r['client_version'] !== null ? " (client {$r['client_version']})" : '');
    }

    /**
     * @param  list<array{tool: string, arguments: list<string>}>|null  $gap
     */
    private static function gap(?array $gap): string
    {
        if ($gap === null) {
            return 'unknown';
        }
        if ($gap === []) {
            return 'none';
        }

        return ClientCapabilities::describeGap(array_column($gap, 'arguments', 'tool'));
    }
}
