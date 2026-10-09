<?php

use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Jobs\ProcessNotification;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('creates a notification and queues processing', function () {
    $response = $this->postJson('/api/notifications', [
        'message' => 'Your code is 123456',
        'priority' => 'critical',
        'recipients' => ['alice@example.com', 'bob@example.com'],
    ]);

    $response->assertCreated()
        ->assertJson([
            'status' => 'pending',
            'priority' => 'critical',
            'recipients_total' => 2,
        ])
        ->assertJsonStructure(['id', 'status', 'priority', 'recipients_total', 'scheduled_at', 'created_at']);

    $notification = Notification::firstOrFail();
    expect($notification->status)->toBe(NotificationStatus::Pending)
        ->and($notification->recipients)->toBe(['alice@example.com', 'bob@example.com']);

    Queue::assertPushed(ProcessNotification::class, fn ($job) => $job->notification->is($notification));
});

it('rejects invalid create payloads', function (array $payload, string $field) {
    $this->postJson('/api/notifications', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor($field);
})->with([
    'missing message' => [['priority' => 'critical', 'recipients' => ['a@example.com']], 'message'],
    'bad priority' => [['message' => 'hi', 'priority' => 'urgent', 'recipients' => ['a@example.com']], 'priority'],
    'empty recipients' => [['message' => 'hi', 'priority' => 'bulk', 'recipients' => []], 'recipients'],
    'past schedule' => [['message' => 'hi', 'priority' => 'bulk', 'recipients' => ['a@example.com'], 'scheduled_at' => '2000-01-01T00:00:00Z'], 'scheduled_at'],
]);

it('shows status with counts and deliveries', function () {
    $notification = Notification::factory()
        ->processing()
        ->recipients(['a@example.com', 'b@example.com'])
        ->create(['recipients_sent' => 1]);
    $notification->deliveries()->createMany([
        ['recipient' => 'a@example.com', 'status' => 'sent', 'attempts' => 1, 'sent_at' => now()],
        ['recipient' => 'b@example.com', 'status' => 'pending', 'attempts' => 0],
    ]);

    $this->getJson("/api/notifications/{$notification->id}")
        ->assertOk()
        ->assertJson([
            'id' => $notification->id,
            'status' => 'processing',
            'counts' => ['total' => 2, 'sent' => 1, 'failed' => 0, 'pending' => 1],
        ])
        ->assertJsonCount(2, 'deliveries');
});

it('returns 404 for an unknown notification', function () {
    $this->getJson('/api/notifications/ntf_missing')->assertNotFound();
});

it('updates a pending notification and recomputes totals', function () {
    $notification = Notification::factory()->priority(Priority::Bulk)->recipients(['a@example.com'])->create();

    $this->patchJson("/api/notifications/{$notification->id}", [
        'priority' => 'critical',
        'recipients' => ['x@example.com', 'y@example.com', 'z@example.com'],
    ])->assertOk()->assertJson([
        'priority' => 'critical',
        'recipients_total' => 3,
    ]);

    expect($notification->refresh()->recipients)->toHaveCount(3)
        ->and($notification->priority)->toBe(Priority::Critical);
});

it('refuses to update or cancel once past pending', function () {
    $notification = Notification::factory()->processing()->create();

    $this->patchJson("/api/notifications/{$notification->id}", ['message' => 'nope'])
        ->assertStatus(409);

    $this->postJson("/api/notifications/{$notification->id}/cancel")
        ->assertStatus(409);
});

it('cancels a pending notification', function () {
    $notification = Notification::factory()->create();

    $this->postJson("/api/notifications/{$notification->id}/cancel")
        ->assertOk()
        ->assertJson(['id' => $notification->id, 'status' => 'cancelled']);

    expect($notification->refresh()->status)->toBe(NotificationStatus::Cancelled);
});
