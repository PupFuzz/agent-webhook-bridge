<?php

namespace Tests\Unit\ClientUpdate;

use App\Bridge\ClientUpdate\ExemptCaller;
use PHPUnit\Framework\TestCase;

/**
 * Every program in this repo that calls the board-tools door WITHOUT being a seat's channel server
 * declares an {@see ExemptCaller} (card#10567 B4). Design review r3-M3 found a third such sender the
 * design had not named (`SshTransportProbe::probeLive`) by deriving the population; this derives it
 * the same way every run, rather than holding a list of the ones found.
 *
 * THE POPULATION, derived on the SUBJECT: a request body names one board tool as a string literal
 * (`'tool' => 'board_…'` in PHP, `"tool": "board_…"` in Python), in `app/` or `bin/`, outside the
 * tests. The channel server (`examples/channel-servers/`) is the seat itself and sends no caller by
 * design, so it is not in the population. Plus every `->sshRoundTrip(` call in `app/`, which is the
 * probe's transport and must send the one probe body.
 *
 * THE CONTROL, below: the predicate is run over the sender lines as they stood before this change,
 * and must flag each — a guard that cannot see the defect it guards is a decoration.
 */
class ExemptCallerSendersTest extends TestCase
{
    private const BODY = '/[\'"]tool[\'"]\s*(=>|:)\s*[\'"]board_/';

    /**
     * @return list<string> "path:line: text" for each line in the population that declares no caller
     */
    private static function undeclared(string $path, string $source): array
    {
        $out = [];
        foreach (explode("\n", $source) as $i => $line) {
            $isBody = preg_match(self::BODY, $line) === 1;
            $isProbeTransport = str_contains($line, '->sshRoundTrip(');
            if (($isBody && ! str_contains($line, 'caller')) || ($isProbeTransport && ! str_contains($line, 'ExemptCaller::probeBody()'))) {
                $out[] = $path.':'.($i + 1).': '.trim($line);
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> path => source
     */
    private static function population(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach (['app', 'bin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = (string) $file;
                $rel = substr($path, strlen($root) + 1);
                if (! preg_match('/\.(php|py|sh)$/', $path) || str_contains($rel, '/tests/') || str_starts_with(basename($path), 'test_')) {
                    continue;
                }
                $files[$rel] = (string) file_get_contents($path);
            }
        }

        return $files;
    }

    public function test_every_non_seat_sender_declares_its_caller(): void
    {
        $bodies = 0;
        $undeclared = [];
        foreach (self::population() as $path => $source) {
            $bodies += preg_match_all(self::BODY, $source);
            $undeclared = array_merge($undeclared, self::undeclared($path, $source));
        }

        $this->assertGreaterThan(0, $bodies, 'the predicate found no sender at all — it has stopped measuring');
        $this->assertSame([], $undeclared, 'a door caller that is not a channel server sends no `caller`, so its call overwrites the seat\'s own report in the fleet ledger');
    }

    /**
     * The three senders as they stood on origin/dev before card#10567 B4 — each must be flagged.
     */
    public function test_the_predicate_flags_each_pre_change_sender(): void
    {
        $before = [
            'app/Bridge/Check/Checks/BoardToolsHttpProbeCheck.php' => "                    ->post(\$endpoint, ['tool' => 'board_my_cards', 'args' => (object) []]);",
            'app/Bridge/Tools/SshTransportProbe.php' => "        \$r = \$this->env->sshRoundTrip(\$target, (string) json_encode(['tool' => 'board_my_cards']));",
            'bin/provision-board-tools.py' => '    payload = json.dumps({"tool": "board_my_cards", "args": {}})',
        ];
        foreach ($before as $path => $line) {
            $this->assertNotSame([], self::undeclared($path, $line), "{$path}'s pre-change sender was not flagged");
        }
    }

    public function test_the_probe_body_declares_the_probe(): void
    {
        $this->assertSame('probe', ExemptCaller::probeBody()['caller']);
        $this->assertSame([], self::undeclared('x', "return ['tool' => 'board_my_cards', 'args' => (object) [], 'caller' => self::Probe->value];"));
    }
}
