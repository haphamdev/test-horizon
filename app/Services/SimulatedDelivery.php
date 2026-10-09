<?php

namespace App\Services;

use App\Enums\Priority;
use App\Exceptions\SimulatedDeliveryException;

class SimulatedDelivery
{
    /**
     * Canned provider errors surfaced on a simulated failure.
     *
     * @var list<string>
     */
    protected array $errors = [
        'provider timeout',
        'temporary rejection',
        'upstream unavailable',
    ];

    /**
     * Pretend to deliver to a single recipient. Sleeps for a tier-dependent
     * latency, then randomly throws to exercise the retry/failure path.
     *
     * @throws SimulatedDeliveryException
     */
    public function send(Priority $priority, string $recipient): void
    {
        $this->pause($priority);

        if ($this->fails()) {
            throw new SimulatedDeliveryException(
                $this->errors[array_rand($this->errors)]." for {$recipient}",
            );
        }
    }

    /**
     * Sleep for a random latency within the tier's configured window.
     */
    protected function pause(Priority $priority): void
    {
        /** @var array{0: int, 1: int} $range */
        $range = config("notifyhub.delivery.latency_ms.{$priority->value}", [0, 0]);
        [$min, $max] = $range;

        if ($max > 0) {
            usleep(random_int($min, $max) * 1000);
        }
    }

    /**
     * Decide whether this attempt fails, based on the configured failure rate.
     * A rate of 0.0 never fails and 1.0 always fails (deterministic for tests).
     */
    protected function fails(): bool
    {
        $rate = (float) config('notifyhub.delivery.failure_rate', 0.0);

        if ($rate <= 0.0) {
            return false;
        }

        if ($rate >= 1.0) {
            return true;
        }

        return lcg_value() < $rate;
    }
}
