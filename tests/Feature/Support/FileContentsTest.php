<?php

namespace Tests\Feature\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Exceptions\UnreadableSecretException;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\FileContents;
use App\Bridge\Support\TokenFile;
use App\Bridge\Writeback\WritebackConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * card#5789 — the read-side sibling of card#5698's assert-absence-off-a-permission-denial
 * class. Four `is_file()`-gated reads converted a present-but-unreadable file into either an
 * undocumented `ErrorException` or (under a logging-only error handler) a confident "absent".
 *
 * EVERY unreadable case guards with skipIfStillReadable: a root runner reads through mode
 * 0000, and without the guard these assertions pass vacuously — a test that cannot fail is a
 * decoration. CI runs as a non-root user, so the guard is a local/container concern.
 *
 * The per-site cases are here rather than spread across four suites deliberately: the four
 * dispositions are ONE ruling with four surfaces, and reading them together is what shows
 * that the fail-soft one is the exception and why.
 */
class FileContentsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/filecontents-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        // Restore readability first — a 0000 file in a directory File::deleteDirectory walks
        // would otherwise leave the temp tree behind on some filesystems.
        foreach ((array) glob($this->dir.'/*') as $f) {
            if (is_string($f)) {
                @chmod($f, 0o644);
            }
        }
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function unreadable(string $name, string $contents = '{}'): string
    {
        $path = $this->dir.'/'.$name;
        File::put($path, $contents);
        chmod($path, 0o000);
        clearstatcache(true, $path);
        if (is_readable($path)) {
            $this->markTestSkipped('this process reads through mode 0000 (running as root?) — the unreadable state is not reachable here');
        }

        return $path;
    }

    // ---- the primitive -------------------------------------------------------------

    public function test_read_returns_the_bytes_of_a_present_readable_file(): void
    {
        File::put($this->dir.'/plain', "hello\n");

        $this->assertSame("hello\n", FileContents::read($this->dir.'/plain', 'thing'));
    }

    public function test_read_returns_null_for_an_absent_file(): void
    {
        $this->assertNull(FileContents::read($this->dir.'/nope', 'thing'));
    }

    public function test_read_throws_for_a_present_but_unreadable_file(): void
    {
        $path = $this->unreadable('locked');

        // Pinned, not assumed: the `is_file` gate the four call sites used PASSES in this
        // state, which is exactly why it was no protection.
        $this->assertTrue(is_file($path), 'precondition: the file is present');

        $this->expectException(UnreadableFileException::class);
        FileContents::read($path, 'thing');
    }

    /**
     * The discriminating control for the case above: an unreadable file must never come back
     * as the absent answer. Both tests would still pass if `read()` returned null for a
     * DIFFERENT unreadable path than the one they use, so the outcome is asserted directly.
     */
    public function test_unreadable_is_never_reported_as_absent(): void
    {
        $path = $this->unreadable('locked');

        $threw = false;
        $result = 'not-set';
        try {
            $result = FileContents::read($path, 'thing');
        } catch (UnreadableFileException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'read() returned '.var_export($result, true).' for an unreadable file instead of throwing');
    }

    public function test_message_names_the_caller_supplied_subject_and_the_path(): void
    {
        $path = $this->unreadable('locked', 'sentinel-contents');

        try {
            FileContents::read($path, 'seen-cursor');
            $this->fail('expected UnreadableFileException');
        } catch (UnreadableFileException $e) {
            $this->assertStringStartsWith('seen-cursor at '.$path, $e->getMessage());
            $this->assertStringContainsString('permissions fault rather than an absence', $e->getMessage());
            $this->assertStringNotContainsString('sentinel-contents', $e->getMessage());
        }
    }

    /**
     * The secret readers keep their narrow type. Six call sites catch
     * UnreadableSecretException; if the hoist had let the base escape from TokenFile they
     * would all have stopped catching, silently.
     */
    public function test_token_reader_still_throws_the_secret_subtype(): void
    {
        $path = $this->unreadable('token', 'super-secret-value');

        try {
            TokenFile::readTrimmed($path);
            $this->fail('expected UnreadableSecretException');
        } catch (UnreadableFileException $e) {
            $this->assertInstanceOf(UnreadableSecretException::class, $e);
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
        }
    }

    // ---- site 1: the seen-cursor propagates ----------------------------------------

    public function test_seen_cursor_absent_or_garbage_still_decodes_to_empty(): void
    {
        $this->assertSame([], BridgePaths::readSeen($this->dir.'/absent.json'));

        File::put($this->dir.'/garbage.json', 'not json at all');
        $this->assertSame([], BridgePaths::readSeen($this->dir.'/garbage.json'));
    }

    /**
     * NOT fail-soft, and the neighbouring writer is why: bridge:inbox filters on this result,
     * so [] re-surfaces every already-seen intent, and updateSeenLocked then opens the same
     * file `c+` and throws regardless — a duplicate inbox followed by a crash. Aborting is
     * the outcome this path already had; only the diagnosis is new.
     */
    public function test_unreadable_seen_cursor_propagates_rather_than_decoding_to_empty(): void
    {
        $path = $this->unreadable('inbox-seen.json', '["a","b"]');

        $threw = false;
        $result = 'not-set';
        try {
            $result = BridgePaths::readSeen($path);
        } catch (UnreadableFileException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'readSeen() returned '.var_export($result, true).' — an unreadable cursor was reported as "no ids seen"');
    }

    /**
     * The FIFTH member, found by the sibling audit while landing the class and not named on
     * the card — same shape with `file()` in place of `file_get_contents()`, so the original
     * grep for the latter could not have surfaced it. It backs the inbox itself, which makes
     * [] the worst possible answer: "no intents staged" for a file the process cannot see
     * into. Its `?: []` fallback was already dead under the console and HTTP kernels.
     */
    public function test_unreadable_jsonl_state_file_propagates_rather_than_reading_as_empty(): void
    {
        $path = $this->unreadable('inbox.jsonl', "{\"id\":\"a\"}\n");

        $threw = false;
        $result = 'not-set';
        try {
            $result = BridgePaths::readJsonl($path);
        } catch (UnreadableFileException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'readJsonl() returned '.var_export($result, true).' — an unreadable inbox was reported as empty');
    }

    public function test_jsonl_reader_still_skips_blank_and_garbage_lines(): void
    {
        File::put($this->dir.'/inbox.jsonl', "{\"id\":\"a\"}\n\nnot json\n{\"id\":\"b\"}\n");

        $this->assertSame(
            [['id' => 'a'], ['id' => 'b']],
            BridgePaths::readJsonl($this->dir.'/inbox.jsonl'),
        );
        $this->assertSame([], BridgePaths::readJsonl($this->dir.'/absent.jsonl'));
    }

    // ---- site 2: shared identities degrade (the one exception) ----------------------

    /**
     * THE ONE FAIL-SOFT MEMBER. Its other caller is the receiver, which must not 5xx over an
     * optional policy file — and SharedIdentitiesCheck has documented this loader as
     * answering [] for an unreadable file all along, which was false until now.
     */
    public function test_unreadable_shared_identities_degrades_to_empty_with_a_warning(): void
    {
        $this->unreadable('shared-identities.json', '{"shared_identities":[]}');
        Log::spy();

        $this->assertSame([], AgentRegistry::loadSharedIdentities($this->dir));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, 'shared-identities.json')
                && str_contains($m, 'permissions fault rather than an absence'))
            ->once();
    }

    // ---- site 3: writeback config raises its own typed channel ---------------------

    public function test_absent_writeback_config_is_writeback_off(): void
    {
        $this->assertNull(WritebackConfig::load($this->dir));
    }

    /**
     * Must NOT be null. Null means "writeback is off", so answering it here would silently
     * disable every card move on the receiver's dispatch path. ConfigException is the channel
     * this loader already has for "this file is bad".
     */
    public function test_unreadable_writeback_config_raises_config_exception_not_null(): void
    {
        $this->unreadable('writeback.json', '{"mappings":{}}');

        $threw = false;
        $result = 'not-set';
        try {
            $result = WritebackConfig::load($this->dir);
        } catch (ConfigException $e) {
            $threw = true;
            $this->assertStringContainsString('permissions fault rather than an absence', $e->getMessage());
        }
        $this->assertTrue($threw, 'load() returned '.var_export($result, true).' — an unreadable policy silently disabled writeback');
    }
}
