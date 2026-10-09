<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether the notification may still be edited or cancelled.
     */
    public function isEditable(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether the notification has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Cancelled], true);
    }
}
