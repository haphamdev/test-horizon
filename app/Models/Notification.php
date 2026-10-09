<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Enums\Priority;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $message
 * @property Priority $priority
 * @property NotificationStatus $status
 * @property array<int, string> $recipients
 * @property Carbon|null $scheduled_at
 * @property int $recipients_total
 * @property int $recipients_sent
 * @property int $recipients_failed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['message', 'priority', 'recipients', 'status', 'scheduled_at', 'recipients_total'])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (Notification $notification): void {
            if (empty($notification->id)) {
                $notification->id = 'ntf_'.(string) Str::ulid();
            }
        });
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /**
     * Whether this notification is still editable / cancellable.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Collapse the per-recipient counters into a terminal status once every
     * delivery has settled. Operates on the current in-memory counters, so the
     * caller is responsible for refreshing them under a lock when racing jobs.
     */
    public function settleIfComplete(): bool
    {
        if ($this->recipients_sent + $this->recipients_failed < $this->recipients_total) {
            return false;
        }

        $this->status = $this->recipients_failed > 0
            ? NotificationStatus::Failed
            : NotificationStatus::Sent;

        $this->save();

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => NotificationStatus::class,
            'recipients' => 'array',
            'scheduled_at' => 'datetime',
            'recipients_total' => 'integer',
            'recipients_sent' => 'integer',
            'recipients_failed' => 'integer',
        ];
    }
}
