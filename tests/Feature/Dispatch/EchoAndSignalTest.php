<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\EchoSuppression;
use App\Bridge\Support\RegisteredAgent;
use App\Bridge\Support\SignalAllowlist;
use Tests\TestCase;

class EchoAndSignalTest extends TestCase
{
    public function test_echo_by_friendly_name(): void
    {
        $echo = EchoSuppression::default('prod-agent');

        $this->assertTrue($echo->isEcho(new Actor(id: '1', name: 'prod-agent')));
        $this->assertFalse($echo->isEcho(new Actor(id: '2', name: 'someone-else')));
    }

    public function test_echo_treat_as_echo_names(): void
    {
        $echo = EchoSuppression::default('prod-agent', treatAsEcho: ['ci-bot']);

        $this->assertTrue($echo->isEcho(new Actor(id: '9', name: 'ci-bot')));
    }

    public function test_echo_by_raw_id_works_without_registry(): void
    {
        // The load-bearing safety net: name is null (registry empty) but the
        // raw id still suppresses.
        $echo = EchoSuppression::default('prod-agent', treatAsEchoIds: ['137']);

        $this->assertTrue($echo->isEcho(new Actor(id: '137', name: null)));
        $this->assertFalse($echo->isEcho(new Actor(id: '138', name: null)));
    }

    public function test_signal_empty_allows_all(): void
    {
        $signal = SignalAllowlist::default([]);

        $this->assertTrue($signal->isSignal(new Actor(id: '1', name: 'anyone')));
        $this->assertTrue($signal->isSignal(new Actor(id: '2', name: null)));
    }

    public function test_signal_non_empty_allows_only_named(): void
    {
        $signal = SignalAllowlist::default(['acme-pm']);

        $this->assertTrue($signal->isSignal(new Actor(id: '1', name: 'acme-pm')));
        $this->assertFalse($signal->isSignal(new Actor(id: '2', name: 'prod-agent')));
        $this->assertFalse($signal->isSignal(new Actor(id: '3', name: null)));
    }

    public function test_signal_unknown_name_throws_fail_closed(): void
    {
        // A treat_as_signal name with no matching agent config is fail-closed
        // (a typo would otherwise silently classify everything NOT-IN-SIGNAL).
        $registry = new AgentRegistry([new RegisteredAgent(name: 'prod-agent', kanbanUserId: 137)]);

        $this->expectException(ConfigException::class);
        SignalAllowlist::default(['typo-agent'], $registry);
    }
}
