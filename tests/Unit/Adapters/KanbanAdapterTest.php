<?php

namespace Tests\Unit\Adapters;

use App\Bridge\Adapters\KanbanAdapter;
use App\Bridge\Exceptions\InvalidEnvelopeException;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class KanbanAdapterTest extends TestCase
{
    private function request(string $body, array $server = []): Request
    {
        return Request::create('/webhooks/kanban?b=5', 'POST', [], [], [], $server, $body);
    }

    private function body(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'event' => 'task.moved',
            'board_id' => 5,
            'delivery_id' => '550e8400-e29b-41d4-a716-446655440000',
            'user_id' => 137,
            'payload' => ['from' => 1, 'to' => 2],
        ], $overrides));
    }

    public function test_parses_required_fields(): void
    {
        $event = (new KanbanAdapter)->parse($this->request($this->body()), $this->body());

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $event->deliveryId);
        $this->assertSame('5', $event->scopeId);          // board_id stringified
        $this->assertSame('task.moved', $event->eventType);
        $this->assertSame('137', $event->actorId);        // user_id stringified
    }

    public function test_null_user_id_yields_null_actor(): void
    {
        $body = $this->body(['user_id' => null]);
        $event = (new KanbanAdapter)->parse($this->request($body), $body);

        $this->assertNull($event->actorId);
    }

    public function test_missing_user_id_key_yields_null_actor(): void
    {
        $body = (string) json_encode(['event' => 'task.created', 'board_id' => 5, 'delivery_id' => 'd1']);
        $event = (new KanbanAdapter)->parse($this->request($body), $body);

        $this->assertNull($event->actorId);
    }

    public function test_missing_required_field_throws(): void
    {
        $body = (string) json_encode(['event' => 'x', 'delivery_id' => 'd1']);  // no board_id

        $this->expectException(InvalidEnvelopeException::class);
        (new KanbanAdapter)->parse($this->request($body), $body);
    }

    public function test_undecodable_body_throws(): void
    {
        $this->expectException(InvalidEnvelopeException::class);
        (new KanbanAdapter)->parse($this->request('not json'), 'not json');
    }

    public function test_non_scalar_field_throws(): void
    {
        $body = $this->body(['board_id' => ['nested' => true]]);

        $this->expectException(InvalidEnvelopeException::class);
        (new KanbanAdapter)->parse($this->request($body), $body);
    }

    public function test_over_length_delivery_id_throws(): void
    {
        $body = $this->body(['delivery_id' => str_repeat('a', 65)]);

        $this->expectException(InvalidEnvelopeException::class);
        (new KanbanAdapter)->parse($this->request($body), $body);
    }

    public function test_over_length_scope_id_throws(): void
    {
        // scope_id column is 128 — an over-length board_id is a deterministic 400,
        // not a DB "data too long" 5xx the upstream would redeliver forever.
        $body = $this->body(['board_id' => str_repeat('9', 129)]);

        $this->expectException(InvalidEnvelopeException::class);
        (new KanbanAdapter)->parse($this->request($body), $body);
    }

    public function test_over_length_event_type_throws(): void
    {
        $body = $this->body(['event' => str_repeat('e', 65)]);   // event_type column is 64

        $this->expectException(InvalidEnvelopeException::class);
        (new KanbanAdapter)->parse($this->request($body), $body);
    }

    public function test_over_length_actor_id_throws(): void
    {
        $body = $this->body(['user_id' => str_repeat('7', 65)]);   // actor_id column is 64

        $this->expectException(InvalidEnvelopeException::class);
        (new KanbanAdapter)->parse($this->request($body), $body);
    }

    public function test_kanban_never_pings(): void
    {
        $event = (new KanbanAdapter)->parse($this->request($this->body()), $this->body());
        $this->assertFalse((new KanbanAdapter)->isPing($event));
    }

    public function test_signature_verification(): void
    {
        $adapter = new KanbanAdapter;
        $secret = 'topsecret';
        $body = $this->body();
        $sig = 'sha256='.hash_hmac('sha256', $body, $secret);

        $valid = $this->request($body, ['HTTP_X_KANBAN_SIGNATURE' => $sig]);
        $this->assertTrue($adapter->verifySignature($valid, $body, $secret));

        $wrong = $this->request($body, ['HTTP_X_KANBAN_SIGNATURE' => 'sha256=deadbeef']);
        $this->assertFalse($adapter->verifySignature($wrong, $body, $secret));

        $missing = $this->request($body);
        $this->assertFalse($adapter->verifySignature($missing, $body, $secret));

        $noPrefix = $this->request($body, ['HTTP_X_KANBAN_SIGNATURE' => hash_hmac('sha256', $body, $secret)]);
        $this->assertFalse($adapter->verifySignature($noPrefix, $body, $secret));
    }
}
