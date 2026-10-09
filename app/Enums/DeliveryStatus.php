<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Whether the delivery has settled (no further attempts will occur).
     */
    public function isSettled(): bool
    {
        return $this === self::Sent || $this === self::Failed;
    }
}
