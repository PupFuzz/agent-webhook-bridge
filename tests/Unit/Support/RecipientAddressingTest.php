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
}
