<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Notification;
use App\Services\SimulatedDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers a single recipient's notification. Retries on simulated failure and,
 * once settled (sent or permanently failed), rolls the parent notification up
 * to its terminal status.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public Delivery $delivery)
    {
        $this->onQueue($delivery->notification->priority->queue());
        $this->tries = (int) config('notifyhub.tries', 3);
    }

    /**
     * Seconds to wait between retry attempts.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('notifyhub.backoff', [5, 10]);
    }

    public function handle(SimulatedDelivery $sender): void
    {
        $delivery = $this->delivery;

        if ($delivery->status === DeliveryStatus::Sent) {
            return;
        }

        $delivery->increment('attempts');

        // Throws SimulatedDeliveryException on a simulated provider failure,
        // which bubbles up to trigger a retry (or failed() when exhausted).
        $sender->send($delivery->notification->priority, $delivery->recipient);

        $delivery->forceFill([
            'status' => DeliveryStatus::Sent,
            'sent_at' => now(),
            'last_error' => null,
        ])->save();

        Notification::whereKey($delivery->notification_id)->increment('recipients_sent');

        $this->rollUp($delivery->notification_id);
    }

    /**
     * Called once retries are exhausted: mark the delivery permanently failed
     * and settle the parent notification if this was the last delivery.
     */
    public function failed(Throwable $exception): void
    {
        $delivery = $this->delivery->fresh();

        if ($delivery === null || $delivery->status->isSettled()) {
            return;
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Failed,
            'last_error' => $exception->getMessage(),
        ])->save();

        Notification::whereKey($delivery->notification_id)->increment('recipients_failed');

        $this->rollUp($delivery->notification_id);
    }

    /**
     * Collapse the notification to sent/failed once every delivery has settled.
     * Serialized with a row lock so racing delivery jobs don't double-settle.
     */
    protected function rollUp(string $notificationId): void
    {
        DB::transaction(function () use ($notificationId) {
            $notification = Notification::whereKey($notificationId)->lockForUpdate()->first();

            if ($notification === null || $notification->status->isTerminal()) {
                return;
            }

            $notification->settleIfComplete();
        });
    }
}
