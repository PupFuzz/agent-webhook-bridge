<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\CoordCredentialStore;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bridge's read of the coord credential store's INI — the SUBSET of the framework's
 * `configparser` grammar it declares (card#11208 / DL-456). Each shape the subset refuses is a
 * named fault, never a guess; each shape it accepts routes exactly as `git-credential-coord` does.
 * The expectations for the framework-accepted shapes were taken from the framework's own parser on
 * this host (Python 3.12 `configparser`, `interpolation=None`, case-preserving names).
 */
class CoordCredentialStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/coord-store-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function store(string $ini): CoordCredentialStore
    {
        File::put($this->dir.'/credentials.ini', $ini);

        return CoordCredentialStore::at($this->dir.'/credentials.ini');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function outsideTheSubset(): array
    {
        return [
            'a line before any section' => ["k = v\n[github]\n", 'line 1 comes before any [section] header'],
            'a UTF-8 BOM (the framework parser fails on it too)' => ["\u{FEFF}[github]\n", 'line 1 comes before any [section] header'],
            'a line with no delimiter' => ["[github]\nghp_bare\n", 'line 2 is neither a comment'],
            'an empty name' => ["[github]\n = v\n", 'line 2 is neither a comment'],
            'a section opened twice' => ["[github]\n[kanban]\n[github]\n", 'line 3 opens a section a second time'],
            'a name set twice' => ["[github]\na_file = /x\na_file = /y\n", 'line 3 sets a name a second time'],
            'a value in [DEFAULT]' => ["[DEFAULT]\nk = v\n[github]\n", 'line 2 sets a value in [DEFAULT]'],
            'a continuation in [github]' => ["[github]\na_file = /x\n  /y\n", 'line 3 continues a value onto an indented line in [github]'],
            'a continuation in the map' => ["[git-credential-map]\ngithub.com/o = a\n  b\n", 'line 3 continues a value onto an indented line in [git-credential-map]'],
            'a non-ASCII name in the map' => ["[git-credential-map]\ngithub.com/ö = a\n", 'line 2 sets a name in [git-credential-map] that is not printable ASCII'],
            'a name with a space in [github]' => ["[github]\nmy key_file = /x\n", 'line 2 sets a name in [github] that is not printable ASCII'],
            'a map value with a space' => ["[git-credential-map]\ngithub.com/o = a b\n", 'to a key name that is not printable ASCII'],
            'a map value with %%' => ["[git-credential-map]\ngithub.com/o = a%%b\n", 'holding `%%` or `%(`'],
            'invalid UTF-8' => ["[github]\nk_file = /\xff\n", 'it is not valid UTF-8'],
        ];
    }

    #[DataProvider('outsideTheSubset')]
    public function test_a_store_outside_the_subset_is_malformed_by_name(string $ini, string $why): void
    {
        $store = $this->store($ini);

        $this->assertSame(CoordCredentialStore::MALFORMED, $store->fault);
        $this->assertStringContainsString($why, $store->faultClause());
        $this->assertStringContainsString('the offending text is not shown', $store->faultClause());
    }

    /**
     * The framework's `looks_like_pasted_secret` examples, plus each prefix and the length edge.
     * Agreement with `coord_credentials.py` was measured once by feeding these same values to it;
     * nothing here re-measures the far end.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function pastedSecretShapes(): array
    {
        return [
            'a classic PAT' => ['ghp_abc', true],
            'a fine-grained PAT' => ['github_pat_abc', true],
            'an upper-cased prefix' => ['GHP_abc', true],
            'a GitLab token' => ['glpat-abc', true],
            'a Slack bot token' => ['xoxb-abc', true],
            'a 24-character blob' => [str_repeat('a', 24), true],
            'a 23-character blob' => [str_repeat('a', 23), false],
            'a 24-character name with a dot' => [str_repeat('a', 20).'.txt', false],
            'the placeholder' => ['REPLACE_ME', false],
            'a home-relative path' => ['~/.config/coord/tok', false],
            'a Windows path' => ['C:\\creds\\tok', false],
            'a file name' => ['tok.txt', false],
            'a prefixed path' => ['/ghp_abc', false],
            'a short key name' => ['coordination', false],
            'blank' => ['  ', false],
        ];
    }

    #[DataProvider('pastedSecretShapes')]
    public function test_a_credential_shaped_name_is_recognised_as_the_framework_recognises_it(string $value, bool $flagged): void
    {
        $this->assertSame($flagged, CoordCredentialStore::looksLikePastedSecret($value));
        $this->assertSame($flagged ? '<a credential-shaped name, '.CoordCredentialStore::fingerprint($value).'>' : $value, CoordCredentialStore::displayName($value));
    }

    public function test_the_fingerprint_is_the_frameworks(): void
    {
        // pointer_fingerprint('ghp_abc') in coord_credentials.py
        $this->assertSame('sha256:'.substr(hash('sha256', 'ghp_abc'), 0, 8), CoordCredentialStore::fingerprint('ghp_abc'));
    }

    public function test_the_shapes_the_framework_accepts_route_as_the_helper_routes(): void
    {
        $store = $this->store(implode("\r\n", [
            '# a comment',
            '   ; an indented comment',
            '[kanban]',
            'api_token_file = ~/.kanban-token',
            '   a continuation in a section the bridge does not read',
            '[github] trailing text after the header, which the framework parser ignores',
            'Owner_file: /abs/owner',
            'url_file = /abs/with=equals:and-colon',
            '',
            '[git-credential-map]',
            'github.com/o = Owner',
            'github.com/o/r=repo',
            'github.com =',
        ]));

        $this->assertNull($store->fault, $store->faultClause());
        $this->assertSame(['key' => 'repo', 'matched' => 'github.com/o/r'], $store->routeFor('o/r'));
        $this->assertSame(['key' => 'Owner', 'matched' => 'github.com/o'], $store->routeFor('/o/s.git'));
        $this->assertNull($store->routeFor('x/y'), 'a blank host line maps nothing');
        $this->assertNull($store->routeFor('O/r'), 'the case written, never folded');
        $this->assertSame(['/abs/owner', null], $store->tokenFileFor('Owner'));
        $this->assertSame(['/abs/owner', null], $store->tokenFileFor('owner'), 'the [github] name folds');
        $this->assertSame(['/abs/with=equals:and-colon', null], $store->tokenFileFor('url'), 'split at the FIRST delimiter');
    }

    public function test_the_candidates_are_the_helpers_ladder(): void
    {
        $this->assertSame(['github.com/o/r', 'github.com/o', 'github.com'], CoordCredentialStore::candidates('o/r'));
        $this->assertSame(['github.com/o/r', 'github.com/o', 'github.com'], CoordCredentialStore::candidates('/o/r.git/'));
        $this->assertSame(['github.com/o', 'github.com'], CoordCredentialStore::candidates('o'));
    }

    public function test_an_absent_store_is_empty_and_readable(): void
    {
        $store = CoordCredentialStore::at($this->dir.'/no-such.ini');

        $this->assertNull($store->fault);
        $this->assertFalse($store->present);
        $this->assertNull($store->routeFor('o/r'));
    }

    public function test_a_store_under_a_directory_this_process_cannot_traverse_is_unreadable_not_absent(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root traverses any directory');
        }
        File::ensureDirectoryExists($this->dir.'/locked');
        chmod($this->dir.'/locked', 0o000);
        try {
            $store = CoordCredentialStore::at($this->dir.'/locked/credentials.ini');
        } finally {
            chmod($this->dir.'/locked', 0o700);
        }

        $this->assertSame(CoordCredentialStore::UNREADABLE, $store->fault);
    }

    public function test_asking_a_failed_read_is_a_caller_bug(): void
    {
        $this->expectException(\LogicException::class);

        $this->store("k = v\n")->routeFor('o/r');
    }
}
