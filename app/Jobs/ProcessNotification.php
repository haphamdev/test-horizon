<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Transitions a pending notification to processing and fans it out into one
 * independent delivery job per recipient, on the notification's priority queue.
 */
class ProcessNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Notification $notification)
    {
        $this->onQueue($notification->priority->queue());
    }

    public function handle(): void
    {
        $notification = $this->notification->fresh();

        // Skip if it was cancelled/edited away from pending before we ran.
        if ($notification === null || $notification->status !== NotificationStatus::Pending) {
            return;
        }

        $deliveries = DB::transaction(function () use ($notification) {
            $notification->update(['status' => NotificationStatus::Processing]);

            return collect($notification->recipients)->map(
                fn (string $recipient) => $notification->deliveries()->create([
                    'recipient' => $recipient,
                    'status' => DeliveryStatus::Pending,
                    'attempts' => 0,
                ]),
            );
        });

        // Dispatch after commit so workers never see uncommitted delivery rows.
        $deliveries->each(fn ($delivery) => DeliverNotification::dispatch($delivery));
    }
}
