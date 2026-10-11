<?php

use App\Jobs\ProcessNotification;
use Illuminate\Support\Facades\Queue;

it('delays processing until scheduled_at, immediate otherwise', function () {
    Queue::fake();

    $this->postJson('/api/notifications', [
        'message' => 'later', 'priority' => 'bulk',
        'recipients' => ['a@example.com'],
        'scheduled_at' => now()->addHour()->toISOString(),
    ])->assertCreated();

    $this->postJson('/api/notifications', [
        'message' => 'now', 'priority' => 'critical',
        'recipients' => ['b@example.com'],
    ])->assertCreated();

    Queue::assertPushed(ProcessNotification::class, fn ($job) => $job->delay !== null && $job->queue === 'bulk');
    Queue::assertPushed(ProcessNotification::class, fn ($job) => $job->delay === null && $job->queue === 'critical');
});
