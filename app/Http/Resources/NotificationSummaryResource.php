<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight representation returned from create / update.
 *
 * @mixin Notification
 */
class NotificationSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'recipients_total' => $this->recipients_total,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
