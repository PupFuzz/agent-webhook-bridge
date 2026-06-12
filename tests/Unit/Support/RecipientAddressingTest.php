<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\RecipientAddressing;
use PHPUnit\Framework\TestCase;

/**
 * #2173 / DL-032 — shared parse for comment-level recipient `TO:` filtering.
 */
class RecipientAddressingTest extends TestCase
{
    public function test_to_line_addressing_an_agent_returns_true(): void
    {
        $body = "Some discussion.\nTO: agentB, agentC\nmore text";
        $this->assertTrue(RecipientAddressing::addresses($body, 'agentB'));
        $this->assertTrue(RecipientAddressing::addresses($body, 'agentC'));
    }

    public function test_to_line_not_naming_the_agent_returns_false(): void
    {
        $this->assertFalse(RecipientAddressing::addresses('TO: agentB, agentC', 'agentA'));
    }

    public function test_all_keyword_addresses_every_agent(): void
    {
        $this->assertTrue(RecipientAddressing::addresses('TO: all', 'anyone'));
        $this->assertTrue(RecipientAddressing::addresses('TO: agentB, all', 'agentZ'));
    }

    public function test_no_to_line_returns_null_for_label_fallback(): void
    {
        $this->assertNull(RecipientAddressing::addresses("just a normal comment\nwith two lines", 'agentA'));
    }

    public function test_matching_is_case_insensitive(): void
    {
        $this->assertTrue(RecipientAddressing::addresses('to: AgentB', 'agentb'));
        $this->assertTrue(RecipientAddressing::addresses('To: Prod-Agent', 'prod-agent'));
    }

    public function test_todo_line_is_not_a_to_line(): void
    {
        // "TODO:" must not be parsed as a recipient line (the ':' must follow "to").
        $this->assertNull(RecipientAddressing::addresses("TODO: ship it\nbody", 'agentA'));
    }

    public function test_first_to_line_wins(): void
    {
        $this->assertTrue(RecipientAddressing::addresses("TO: agentA\nTO: agentB", 'agentA'));
        $this->assertFalse(RecipientAddressing::addresses("TO: agentA\nTO: agentB", 'agentB'));
    }

    public function test_bare_or_empty_to_line_is_treated_as_absent_not_suppress_all(): void
    {
        // A malformed/empty TO: must NOT silently suppress everyone — fall back.
        $this->assertNull(RecipientAddressing::addresses('TO:', 'agentA'));
        $this->assertNull(RecipientAddressing::addresses('TO:   ,  ,', 'agentA'));
    }

    public function test_whitespace_and_no_space_after_colon(): void
    {
        $this->assertTrue(RecipientAddressing::addresses('  TO:agentB ', 'agentB'));
        $this->assertSame(['agentb', 'agentc'], RecipientAddressing::recipients('TO:  agentB ,  agentC '));
    }

    public function test_recipients_returns_null_with_no_to_line(): void
    {
        $this->assertNull(RecipientAddressing::recipients("plain comment\nsecond line"));
    }

    public function test_crlf_and_cr_line_endings_split(): void
    {
        // \R is the load-bearing split — cover CRLF and bare CR, not just \n.
        $this->assertTrue(RecipientAddressing::addresses("intro\r\nTO: agentB\r\nbody", 'agentB'));
        $this->assertTrue(RecipientAddressing::addresses("intro\rTO: agentB\rbody", 'agentB'));
    }

    // --- author() (FROM: line) — DL-034 -------------------------------------

    public function test_author_returns_the_from_line_name(): void
    {
        $this->assertSame('agenta', RecipientAddressing::author("FROM: agentA\nTO: agentB\nbody"));
    }

    public function test_author_is_case_insensitive_and_trimmed(): void
    {
        $this->assertSame('agenta', RecipientAddressing::author("  From:   AgentA  \nbody"));
    }

    public function test_author_returns_null_with_no_from_line(): void
    {
        $this->assertNull(RecipientAddressing::author("plain comment\nTO: agentB"));
    }

    public function test_author_bare_or_empty_from_is_absent(): void
    {
        $this->assertNull(RecipientAddressing::author("FROM:\nbody"));
        $this->assertNull(RecipientAddressing::author("FROM:    \nbody"));
    }

    public function test_author_first_from_line_wins(): void
    {
        $this->assertSame('first', RecipientAddressing::author("FROM: first\nFROM: second"));
    }

    public function test_author_does_not_match_a_word_starting_with_from(): void
    {
        // The `:` must immediately follow `from` — `FROMAGE:` is not a FROM line.
        $this->assertNull(RecipientAddressing::author("FROMAGE: cheese\nbody"));
    }

    public function test_author_splits_crlf_and_cr(): void
    {
        $this->assertSame('agenta', RecipientAddressing::author("intro\r\nFROM: agentA\r\nbody"));
        $this->assertSame('agenta', RecipientAddressing::author("intro\rFROM: agentA\rbody"));
    }

    public function test_author_trims_a_decorated_from_to_the_first_token(): void
    {
        // #2202: a decorated FROM line must still match `author($body) === $agent`
        // — return the first token, not the verbatim tail.
        $this->assertSame('alice', RecipientAddressing::author('FROM: alice (pls review)'));
        $this->assertSame('alice', RecipientAddressing::author("FROM: alice\tsenior\nbody"));
    }

    public function test_author_takes_the_first_name_of_a_multi_name_from(): void
    {
        // A FROM line names ONE author; a comma list collapses to the first
        // (symmetric with recipients() tokenizing on commas).
        $this->assertSame('alice', RecipientAddressing::author('FROM: alice, bob'));
        $this->assertSame('alice', RecipientAddressing::author('FROM: ,alice'));
    }
}
