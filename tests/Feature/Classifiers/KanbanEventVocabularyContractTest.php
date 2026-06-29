<?php

namespace Tests\Feature\Classifiers;

use Tests\TestCase;

/**
 * Consumer/producer contract: every kanban webhook-event literal the bridge
 * classifiers reference MUST be a real event in kanban-board's producer
 * vocabulary. Drift either way is a silent bug — a classifier keyed on an event
 * kanban no longer emits (a rename/removal) would simply never fire, looking
 * like a legitimate no-match.
 *
 * Hermetic by construction: the producer vocabulary is a checked-in snapshot
 * (tests/Fixtures/kanban-webhook-events.json — regen recipe in that file) and
 * the referenced events are discovered by scanning the classifier source on
 * disk. NO network, NO kanban checkout required at test time.
 *
 * The scan is restricted to literals whose DOMAIN is one kanban actually
 * defines, so non-event dotted strings (e.g. the `bridge.config_dir` config
 * key) and the GitHub provider's events (`pull_request.*` / `push`) are not
 * mistaken for kanban events.
 */
class KanbanEventVocabularyContractTest extends TestCase
{
    /**
     * The core kanban events the default InboxOnlyClassifier consumes. Asserted
     * as a discovered-set floor so a broken scan can't pass vacuously.
     *
     * @var list<string>
     */
    private const CORE_CONSUMED = [
        'task.created',
        'task.moved',
        'task.updated',
        'task.deleted',
        'task.archived',
        'task.restored',
        'task.unarchived',
    ];

    public function test_every_referenced_kanban_event_is_in_producer_vocabulary(): void
    {
        $vocabulary = $this->producerVocabulary();
        $domains = array_values(array_unique(array_map(
            static fn (string $name): string => explode('.', $name, 2)[0],
            $vocabulary,
        )));

        $referenced = $this->referencedKanbanEvents($domains);

        // Non-vacuous: the scan must actually find the events we know are consumed.
        $this->assertNotEmpty($referenced, 'the classifier source scan found no kanban event literals — the discovery regex has likely broken');
        foreach (self::CORE_CONSUMED as $core) {
            $this->assertContains($core, $referenced, "expected the source scan to discover the core consumed event '{$core}'");
        }

        foreach ($referenced as $event) {
            $this->assertContains(
                $event,
                $vocabulary,
                "a bridge classifier references kanban event '{$event}', which is NOT in the kanban WebhookEvents producer vocabulary "
                .'(tests/Fixtures/kanban-webhook-events.json) — the consumer/producer event contract has drifted',
            );
        }
    }

    /**
     * The kanban-board producer vocabulary, from the checked-in snapshot.
     *
     * @return list<string>
     */
    private function producerVocabulary(): array
    {
        $raw = file_get_contents(base_path('tests/Fixtures/kanban-webhook-events.json'));
        $this->assertNotFalse($raw, 'could not read the kanban webhook-events fixture');
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('events', $decoded);
        $this->assertIsArray($decoded['events']);

        return array_values(array_map(strval(...), $decoded['events']));
    }

    /**
     * Kanban event literals referenced anywhere in the classifier source — every
     * `<domain>.<lifecycle>` string literal whose domain is one kanban defines.
     *
     * @param  list<string>  $producerDomains
     * @return list<string>
     */
    private function referencedKanbanEvents(array $producerDomains): array
    {
        $found = [];
        $files = glob(app_path('Bridge/Classifiers/*.php'));
        $this->assertNotEmpty($files, 'no classifier source files found to scan');
        foreach ($files as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            preg_match_all('/[\'"]([a-z_]+\.[a-z_]+)[\'"]/', $src, $matches);
            foreach ($matches[1] as $literal) {
                $domain = explode('.', $literal, 2)[0];
                if (in_array($domain, $producerDomains, true)) {
                    $found[$literal] = true;
                }
            }
        }

        return array_keys($found);
    }
}
