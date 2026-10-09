<?php

use App\Enums\DeliveryStatus;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Exceptions\SimulatedDeliveryException;
use App\Jobs\DeliverNotification;
use App\Jobs\ProcessNotification;
use App\Models\Delivery;
use App\Models\Notification;
use App\Services\SimulatedDelivery;
use Illuminate\Support\Facades\Queue;

it('fans a notification out into one delivery job per recipient on its priority queue', function () {
    Queue::fake();

    $notification = Notification::factory()
        ->priority(Priority::Bulk)
        ->recipients(['a@example.com', 'b@example.com', 'c@example.com'])
        ->create();

    (new ProcessNotification($notification))->handle();

    expect($notification->refresh()->status)->toBe(NotificationStatus::Processing)
        ->and($notification->deliveries)->toHaveCount(3);

    Queue::assertPushed(DeliverNotification::class, 3);
    Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->queue === 'bulk');
});

it('does not fan out a notification that is no longer pending', function () {
    Queue::fake();

    $notification = Notification::factory()->processing()->create();

    (new ProcessNotification($notification))->handle();

    Queue::assertNothingPushed();
    expect($notification->deliveries()->count())->toBe(0);
});

it('routes the process job onto the matching priority queue', function (Priority $priority) {
    $notification = Notification::factory()->priority($priority)->create();

    expect((new ProcessNotification($notification))->queue)->toBe($priority->value);
})->with([
    'critical' => [Priority::Critical],
    'transactional' => [Priority::Transactional],
    'bulk' => [Priority::Bulk],
]);

it('marks a delivery sent and rolls the notification up to sent', function () {
    config(['notifyhub.failure_rate' => 0.0, 'notifyhub.delivery.failure_rate' => 0.0]);

    $notification = Notification::factory()->processing()->recipients(['only@example.com'])->create();
    $delivery = Delivery::factory()->for($notification)->create(['recipient' => 'only@example.com']);

    (new DeliverNotification($delivery))->handle(app(SimulatedDelivery::class));

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($notification->refresh()->recipients_sent)->toBe(1)
        ->and($notification->status)->toBe(NotificationStatus::Sent);
});

it('throws so the queue retries when a delivery fails', function () {
    config(['notifyhub.delivery.failure_rate' => 1.0]);

    $notification = Notification::factory()->processing()->recipients(['x@example.com'])->create();
    $delivery = Delivery::factory()->for($notification)->create(['recipient' => 'x@example.com']);

    $job = new DeliverNotification($delivery);

    expect(fn () => $job->handle(app(SimulatedDelivery::class)))
        ->toThrow(SimulatedDeliveryException::class);

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1);
});

it('marks a delivery failed on exhaustion and rolls the notification up to failed', function () {
    $notification = Notification::factory()->processing()->recipients(['x@example.com'])->create();
    $delivery = Delivery::factory()->for($notification)->create(['recipient' => 'x@example.com', 'attempts' => 3]);

    (new DeliverNotification($delivery))->failed(new SimulatedDeliveryException('provider timeout'));

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->last_error)->toBe('provider timeout')
        ->and($notification->refresh()->recipients_failed)->toBe(1)
        ->and($notification->status)->toBe(NotificationStatus::Failed);
});

it('settles to failed when some deliveries succeed and some fail', function () {
    config(['notifyhub.delivery.failure_rate' => 0.0]);

    $notification = Notification::factory()->processing()->recipients(['ok@example.com', 'bad@example.com'])->create();
    $good = Delivery::factory()->for($notification)->create(['recipient' => 'ok@example.com']);
    $bad = Delivery::factory()->for($notification)->create(['recipient' => 'bad@example.com']);

    (new DeliverNotification($good))->handle(app(SimulatedDelivery::class));
    expect($notification->refresh()->status)->toBe(NotificationStatus::Processing);

    (new DeliverNotification($bad))->failed(new SimulatedDeliveryException('upstream unavailable'));

    expect($notification->refresh()->status)->toBe(NotificationStatus::Failed)
        ->and($notification->recipients_sent)->toBe(1)
        ->and($notification->recipients_failed)->toBe(1);
});
