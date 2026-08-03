<?php

namespace Tests\Feature\Console\Check;

use App\Bridge\Support\PathVisibility;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * A TRIPWIRE ON THE ADOPTER SET of the shared not-visible guard (card#5698).
 *
 * WHY IT EXISTS: `UnvalidatedCallSiteTest` pins where the `unvalidated` severity is
 * CONSTRUCTED, and the card#5698 hoist collapsed seven adopting call sites into ONE
 * construction inside {@see PathVisibility}. That made the adopters
 * invisible to it — a check DROPPING its guard and going back to asserting absence off a
 * bare stat moves no number there. Since re-minting exactly that defect at the next stat
 * is the failure this class was carded for, the adopter set needs its own pin.
 *
 * WHAT IT DOES: counts BOTH of the guard's doors per file under `app/` —
 * `PathVisibility::unverifiedUnlessVisible(` (stat, then decide) and
 * `PathVisibility::notVisibleFinding(` (a caller whose failed read already decided).
 * Removing a guard, or adding one, reds this test and forces a deliberate update.
 *
 * BOTH DOORS COUNT BECAUSE THE PIN IS ABOUT THE VERDICT, NOT THE STAT. The second door
 * arrived with card#5698's channel-token slice, where the classification happens inside
 * `ChannelToken::read` and re-statting at the check would measure the file a second time.
 * Counting only the statting door would have let that adopter drop its guard — and go back
 * to convicting the push off a read it made as the wrong OS user — without moving a number
 * anywhere.
 *
 * WHAT IT DOES NOT DO, stated plainly:
 *  - **It cannot tell anyone a NEW stat needs the guard.** A leg added tomorrow that reads
 *    `is_file()` and asserts absence is invisible here — there is no adoption to count.
 *    That judgment is prose, in `PathVisibility`'s docblock, and no test can apply it.
 *  - **It says nothing about whether an adoption is REACHED,** or whether the message it
 *    guards is correct. The per-check tests assert the behaviour; this only asserts the
 *    guard is still wired in.
 *  - **It is lexical, over COMMENT-STRIPPED source.** A dynamic call would not contain the
 *    literal and would be counted nowhere; no such call exists in `app/` today, which is
 *    what makes this exhaustive NOW rather than by construction. Comments are stripped
 *    because the guard's own docblock carries a usage example — counting prose would red
 *    this pin on a doc reword and green it on a deleted call, i.e. exactly backwards.
 *  - **Two sites are deliberately ABSENT and must stay absent** — see the constant below.
 */
class PathVisibilityAdoptionTest extends TestCase
{
    /**
     * Every `app/` file that adopts the guard, with its call count.
     *
     * DELIBERATELY NOT HERE, and both are reasoned decisions rather than oversights:
     *  - `InstallConfigDirCheck` — its `fail` is earned in BOTH worlds, because
     *    `CheckCommand` gates the whole agent loop on the same `is_dir()`, so the run is
     *    certainly degraded either way. It carries the two causes in its MESSAGE instead.
     *    ⚠ That is a statement about its OWN `is_dir()` leg only. Since card#5774 the check
     *    does reach the guard indirectly, through the `DirectoryPermissions` entry below —
     *    but it cannot reach the arm behind it (that same `is_dir()` gate runs first), so
     *    the adoption is the primitive's, not this check's, and it stays off this list.
     *  - `AgentApiTokenCheck` — its message already says "not readable", which is true
     *    under EACCES and ENOENT alike. A claim its evidence supports is not this defect.
     *
     * @var array<string, int>
     */
    private const ADOPTERS = [
        // The channel snapshot legs — the original implementation, now a consumer of the
        // hoisted guard. TWO populations whose traversability is an independent question:
        // the configured path, and the deployed directory.
        'app/Bridge/Support/ChannelSnapshotProbe.php' => 2,
        // channel.socket parent dir — "does not exist" also sent the operator to repoint
        // channel.socket, which is the wrong action when the dir is merely unseeable.
        'app/Bridge/Check/Checks/ChannelTransportCheck.php' => 1,
        // writeback alert-channel socket parent dir.
        'app/Bridge/Check/Checks/WritebackAlertChannelCheck.php' => 1,
        // per-(provider, scope) webhook secret — "run bridge:provision" is likewise the
        // wrong action for a secret that exists but cannot be seen.
        'app/Bridge/Check/Checks/AgentWebhookSecretCheck.php' => 1,
        // the kanban writeback token.
        'app/Bridge/Check/Checks/WritebackTokenCheck.php' => 1,
        // the board-tools bearer. The only adopter whose pre-fix severity was `fail`, so
        // it is the one where the overclaim flipped bridge:check's EXIT CODE.
        'app/Bridge/Tools/BoardToolAgentResolver.php' => 1,
        // the shared secret-dir permission verdict (card#5774). The only adopter that is a
        // PRIMITIVE rather than a check: a failed `fileperms()` meant "measured and clean"
        // here, so the guard sits at the return value both dir checks read.
        'app/Bridge/Check/DirectoryPermissions.php' => 1,
        // the channel auth token (card#5698 sub-shape 2) — the only adopter through the
        // MESSAGE door: `ChannelToken::read` already classified why it failed, so this leg
        // has the answer and must not re-measure to render it.
        'app/Bridge/Check/Checks/ChannelTokenPathCheck.php' => 1,
    ];

    public function test_the_guard_adoption_sites_are_exactly_these(): void
    {
        $found = $this->adoptionSitesUnderApp();

        // Non-vacuous: a broken scan returns nothing, and an empty-vs-empty compare would
        // green through a sweep that dropped every guard.
        $this->assertNotEmpty($found, 'the scan found no adoption sites at all — the scan is broken, not the code');

        ksort($found);
        $expected = self::ADOPTERS;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            "the set of PathVisibility adoption sites has MOVED.\n".
            "A REMOVED guard means that leg is asserting absence off a bare stat again — the exact defect card#5698 closed.\n".
            'A NEW one is fine: add it to the list in the same commit.',
        );
    }

    /**
     * @return array<string, int>
     */
    private function adoptionSitesUnderApp(): array
    {
        $root = base_path('app');
        $counts = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = $this->codeWithoutComments($file->getPathname());
            $n = substr_count($code, 'PathVisibility::unverifiedUnlessVisible(')
                + substr_count($code, 'PathVisibility::notVisibleFinding(');
            if ($n > 0) {
                $counts[str_replace(base_path().'/', '', $file->getPathname())] = $n;
            }
        }

        return $counts;
    }

    /**
     * Comments STRIPPED before counting, or the pin measures the wrong thing: the guard's
     * own docblock carries a usage example and the channel probe's cites it with `{@see}`,
     * so a raw text scan counted three PROSE mentions as adoptions — and would then have
     * red on a doc reword while staying green on a deleted call.
     */
    private function codeWithoutComments(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
