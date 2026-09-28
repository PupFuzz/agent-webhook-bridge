<?php

namespace Tests\Unit\ClientUpdate;

use PHPUnit\Framework\TestCase;

/**
 * `seat_client_events` is append-only (card#10567 B4) — STATED HONESTLY: this is a static check on
 * `app/` as written, not a database guarantee. Nothing may update, delete, upsert, increment or
 * truncate a row through the model or the table; inserting new rows is the only write.
 *
 * The control below runs the predicate over each forbidden shape and must flag it.
 *
 * ⚠ ITS BOUND: a write through a VARIABLE holding a fetched model (`$e = SeatClientEvent::…->first();`
 * then `$e->save()` in a later statement) is not seen — the statement that writes does not name the
 * subject. Review owns that shape; this owns the query-builder and static ones.
 */
class SeatClientEventsNoWriterTest extends TestCase
{
    private const SUBJECT = '/SeatClientEvent\b|[\'"]seat_client_events[\'"]/';

    private const WRITES = '/->\s*(update|delete|forceDelete|upsert|increment|decrement|truncate|updateOrCreate|updateOrInsert|updateQuietly|deleteQuietly|push)\s*\(|::\s*(destroy|truncate)\s*\(/';

    /**
     * A write is flagged when it appears in the same statement as the subject: statements are cut at
     * `;` so a query chain split across lines is read whole.
     *
     * @return list<string>
     */
    private static function writes(string $path, string $source): array
    {
        $out = [];
        foreach (explode(';', $source) as $statement) {
            if (preg_match(self::SUBJECT, $statement) === 1 && preg_match(self::WRITES, $statement) === 1) {
                $out[] = $path.': '.trim((string) preg_replace('/\s+/', ' ', $statement));
            }
        }

        return $out;
    }

    public function test_nothing_in_app_rewrites_or_removes_an_event(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        $mentions = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/app", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! str_ends_with((string) $file, '.php')) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            $mentions += preg_match_all(self::SUBJECT, $source);
            $hits = array_merge($hits, self::writes(substr((string) $file, strlen($root) + 1), $source));
        }

        $this->assertGreaterThan(0, $mentions, 'the subject predicate matched nothing — it has stopped measuring');
        $this->assertSame([], $hits);
    }

    public function test_the_predicate_flags_each_forbidden_write(): void
    {
        foreach ([
            "SeatClientEvent::query()->where('id', 1)->update(['reason' => 'x'])",
            "SeatClientEvent::query()\n    ->where('agent', \$a)\n    ->delete()",
            'SeatClientEvent::destroy(1)',
            "DB::table('seat_client_events')->truncate()",
            "SeatClientEvent::query()->upsert([], ['id'])",
        ] as $source) {
            $this->assertNotSame([], self::writes('x', $source), "not flagged: {$source}");
        }
        $this->assertSame([], self::writes('x', "SeatClientEvent::query()->insert(['agent' => 'a'])"), 'an insert is the one write allowed');
    }
}
