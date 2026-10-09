<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
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
            'message' => $this->message,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'counts' => [
                'total' => $this->recipients_total,
                'sent' => $this->recipients_sent,
                'failed' => $this->recipients_failed,
                'pending' => $this->recipients_total - $this->recipients_sent - $this->recipients_failed,
            ],
            'deliveries' => DeliveryResource::collection($this->whenLoaded('deliveries')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
