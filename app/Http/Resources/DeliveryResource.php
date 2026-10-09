<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Delivery
 */
class DeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'recipient' => $this->recipient,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at?->toISOString(),
            'last_error' => $this->last_error,
        ];
    }
}
