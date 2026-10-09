<?php

namespace App\Http\Controllers;

use App\Enums\NotificationStatus;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Requests\UpdateNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\NotificationSummaryResource;
use App\Jobs\ProcessNotification;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    /**
     * Accept a notification and queue it for asynchronous delivery.
     */
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $recipients = $data['recipients'];

        $notification = Notification::create([
            'message' => $data['message'],
            'priority' => $data['priority'],
            'recipients' => $recipients,
            'status' => NotificationStatus::Pending,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'recipients_total' => count($recipients),
        ]);

        $this->dispatchProcessing($notification);

        return NotificationSummaryResource::make($notification)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return the current status and per-recipient delivery breakdown.
     */
    public function show(Notification $notification): NotificationResource
    {
        return NotificationResource::make($notification->load('deliveries'));
    }

    /**
     * Update a notification while it is still pending.
     */
    public function update(UpdateNotificationRequest $request, Notification $notification): NotificationSummaryResource
    {
        $this->ensureEditable($notification);

        $data = $request->validated();

        if (array_key_exists('recipients', $data)) {
            $data['recipients_total'] = count($data['recipients']);
        }

        $notification->update($data);

        return NotificationSummaryResource::make($notification);
    }

    /**
     * Cancel a pending notification before any delivery happens.
     */
    public function cancel(Notification $notification): JsonResponse
    {
        $this->ensureEditable($notification);

        $notification->update(['status' => NotificationStatus::Cancelled]);

        return response()->json([
            'id' => $notification->id,
            'status' => $notification->status->value,
        ]);
    }

    /**
     * Guard mutations behind the pending state.
     */
    protected function ensureEditable(Notification $notification): void
    {
        abort_unless(
            $notification->isEditable(),
            Response::HTTP_CONFLICT,
            'Notification is not editable in its current status.',
        );
    }

    /**
     * Enqueue fan-out now, or delay it until the scheduled time.
     */
    protected function dispatchProcessing(Notification $notification): void
    {
        $job = ProcessNotification::dispatch($notification);

        if ($notification->scheduled_at !== null) {
            $job->delay($notification->scheduled_at);
        }
    }
}
