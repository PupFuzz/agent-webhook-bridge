<?php

namespace Tests\Feature\Dispatch;

use App\Bridge\Dispatch\Actor;
use App\Bridge\Support\AgentRegistry;
use App\Bridge\Support\EchoSuppression;
use App\Bridge\Support\RegisteredAgent;
use App\Bridge\Support\SignalAllowlist;
use Illuminate\Support\Facades\Log;
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

    public function test_signal_warns_on_name_not_in_registry(): void
    {
        Log::spy();
        $registry = new AgentRegistry([new RegisteredAgent(name: 'prod-agent', kanbanUserId: 137)]);

        SignalAllowlist::default(['typo-agent'], $registry);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $msg) => str_contains($msg, 'treat_as_signal') && str_contains($msg, 'typo-agent')
        )->once();
    }
}
