<?php

namespace Tests\Feature\Workflows;

use Tests\TestCase;

/**
 * `docs/provider-adapters.md` KEEPS ONE CLAUSE of `WebhookAdapter::parse()`'s contract
 * that the interface owns, and this is the drift check canon #16 requires in exchange
 * for keeping it (DL-315 Decision 6's correction).
 *
 * WHY A COPY EXISTS AT ALL, given #16 prefers deleting one. The doc's own instruction to
 * a non-`sha256=` provider — *implement `WebhookAdapter` directly without extending
 * `AbstractWebhookAdapter`* — is the single path that skips `decodeJson()`, i.e. the path
 * that inherits none of the refusal. The warning has to sit at that decision point to be
 * read at all, and a bare pointer with no consequence is skippable. So the copy is one
 * CLAUSE beside a pointer, not the four-line `@throws` reproduction that stood here and
 * had drifted into permitting exactly what the interface forbids.
 *
 * WHAT IS PINNED IS THE LOAD-BEARING TERM, NOT THE PROSE. The failure this guards is
 * SEMANTIC: the previous copy said "undecodable JSON", which is TRUE of a bad body and
 * FALSE of `5` — a reader following it writes an adapter that accepts a JSON scalar. So
 * the assertion is that both surfaces still say the refusal is about not decoding to an
 * ARRAY. Rewording either side without the other reds; rewording both together passes,
 * which is correct — the point is that they move as one, not that the words are frozen.
 *
 * ⛔ THIS IS A PRESENCE PAIR, NOT AN ABSENCE ASSERTION. A test that only checked the
 * stale phrase was gone would certify whatever replaced it, including nothing at all.
 */
class AdapterContractRestatementTest extends TestCase
{
    private const CLAUSE = 'does not decode to an array';

    public function test_the_interface_states_the_array_refusal(): void
    {
        $src = (string) file_get_contents(base_path('app/Bridge/Contracts/WebhookAdapter.php'));

        $this->assertStringContainsString(
            self::CLAUSE,
            $src,
            'WebhookAdapter::parse() is the OWNER of this contract and no longer states it; '.
            'docs/provider-adapters.md points here, so the pointer now leads nowhere.'
        );
    }

    public function test_the_provider_guide_repeats_the_array_refusal_at_the_extension_point(): void
    {
        $doc = (string) file_get_contents(base_path('docs/provider-adapters.md'));

        // The clause is owed specifically where the doc sends an author AROUND
        // AbstractWebhookAdapter — that instruction is what makes the refusal the
        // author's own problem, and a warning anywhere else is not read in time.
        $this->assertStringContainsString(
            'without extending `AbstractWebhookAdapter`',
            $doc,
            'the extension-point instruction moved or was reworded; re-site the refusal warning with it'
        );
        $this->assertStringContainsString(
            self::CLAUSE,
            $doc,
            'docs/provider-adapters.md no longer states what parse() must refuse. It previously said '.
            '"undecodable JSON", which PERMITS the JSON scalar the interface forbids — the defect this pins.'
        );
    }
}
