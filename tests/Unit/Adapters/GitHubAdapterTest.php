<?php

namespace Tests\Unit\Adapters;

use App\Bridge\Adapters\GitHubAdapter;
use App\Bridge\Exceptions\InvalidEnvelopeException;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class GitHubAdapterTest extends TestCase
{
    /**
     * @param  array<string, string>  $headers
     */
    private function request(string $body, array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/webhooks/github?b=acme-corp/widget', 'POST', [], [], [], $server, $body);
    }

    private function defaultHeaders(): array
    {
        return ['X-GitHub-Delivery' => 'gh-delivery-1', 'X-GitHub-Event' => 'pull_request'];
    }

    public function test_composite_event_type_with_action(): void
    {
        $body = (string) json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'acme-corp/widget'],
            'sender' => ['login' => 'octocat'],
        ]);

        $event = (new GitHubAdapter)->parse($this->request($body, $this->defaultHeaders()), $body);

        $this->assertSame('gh-delivery-1', $event->deliveryId);
        $this->assertSame('pull_request.opened', $event->eventType);
        $this->assertSame('acme-corp/widget', $event->scopeId);
        $this->assertSame('octocat', $event->actorId);
    }

    public function test_event_type_without_action(): void
    {
        $body = (string) json_encode(['repository' => ['full_name' => 'acme-corp/widget']]);
        $headers = ['X-GitHub-Delivery' => 'd', 'X-GitHub-Event' => 'push'];

        $event = (new GitHubAdapter)->parse($this->request($body, $headers), $body);

        $this->assertSame('push', $event->eventType);
        $this->assertNull($event->actorId);
    }

    public function test_ping_event(): void
    {
        $body = (string) json_encode(['zen' => 'Design for failure.']);
        $headers = ['X-GitHub-Delivery' => 'd', 'X-GitHub-Event' => 'ping'];

        $adapter = new GitHubAdapter;
        $event = $adapter->parse($this->request($body, $headers), $body);

        $this->assertSame('ping', $event->eventType);
        $this->assertSame('', $event->scopeId);   // ping carries no repository
        $this->assertTrue($adapter->isPing($event));
    }

    public function test_missing_delivery_header_throws(): void
    {
        $body = (string) json_encode(['action' => 'opened']);

        $this->expectException(InvalidEnvelopeException::class);
        (new GitHubAdapter)->parse($this->request($body, ['X-GitHub-Event' => 'pull_request']), $body);
    }

    public function test_missing_event_header_throws(): void
    {
        $body = (string) json_encode(['action' => 'opened']);

        $this->expectException(InvalidEnvelopeException::class);
        (new GitHubAdapter)->parse($this->request($body, ['X-GitHub-Delivery' => 'd']), $body);
    }

    public function test_undecodable_body_throws(): void
    {
        $this->expectException(InvalidEnvelopeException::class);
        (new GitHubAdapter)->parse($this->request('not json', $this->defaultHeaders()), 'not json');
    }

    public function test_signature_uses_hub_header(): void
    {
        $adapter = new GitHubAdapter;
        $secret = 's3cr3t';
        $body = (string) json_encode(['action' => 'opened']);
        $sig = 'sha256='.hash_hmac('sha256', $body, $secret);

        $valid = $this->request($body, array_merge($this->defaultHeaders(), ['X-Hub-Signature-256' => $sig]));
        $this->assertTrue($adapter->verifySignature($valid, $body, $secret));

        $kanbanHeader = $this->request($body, array_merge($this->defaultHeaders(), ['X-Kanban-Signature' => $sig]));
        $this->assertFalse($adapter->verifySignature($kanbanHeader, $body, $secret));
    }
}
