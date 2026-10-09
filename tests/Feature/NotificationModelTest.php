<?php

use App\Enums\DeliveryStatus;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Delivery;
use App\Models\Notification;

it('persists a notification with a prefixed id and enum casts', function () {
    $notification = Notification::factory()
        ->priority(Priority::Critical)
        ->recipients(['a@example.com', 'b@example.com'])
        ->create();

    expect($notification->id)->toStartWith('ntf_')
        ->and($notification->priority)->toBe(Priority::Critical)
        ->and($notification->status)->toBe(NotificationStatus::Pending)
        ->and($notification->recipients)->toBe(['a@example.com', 'b@example.com'])
        ->and($notification->recipients_total)->toBe(2);
});

it('relates deliveries to a notification', function () {
    $notification = Notification::factory()->create();
    $delivery = Delivery::factory()->for($notification)->sent()->create();

    expect($notification->deliveries)->toHaveCount(1)
        ->and($notification->deliveries->first()->is($delivery))->toBeTrue()
        ->and($delivery->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->notification->is($notification))->toBeTrue();
});

it('exposes editability only while pending', function () {
    $pending = Notification::factory()->create();
    $processing = Notification::factory()->processing()->create();

    expect($pending->isEditable())->toBeTrue()
        ->and($processing->isEditable())->toBeFalse();
});
