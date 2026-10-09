<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recipients = fake()->randomElements(
            array_map(fn (int $i): string => "user{$i}@example.com", range(1, 10)),
            fake()->numberBetween(1, 3),
        );

        return [
            'message' => fake()->sentence(),
            'priority' => fake()->randomElement(Priority::cases()),
            'status' => NotificationStatus::Pending,
            'recipients' => $recipients,
            'scheduled_at' => null,
            'recipients_total' => count($recipients),
            'recipients_sent' => 0,
            'recipients_failed' => 0,
        ];
    }

    public function priority(Priority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    /**
     * @param  list<string>  $recipients
     */
    public function recipients(array $recipients): static
    {
        return $this->state(fn (array $attributes): array => [
            'recipients' => $recipients,
            'recipients_total' => count($recipients),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NotificationStatus::Processing,
        ]);
    }

    public function scheduled(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'scheduled_at' => $at ?? now()->addHour(),
        ]);
    }
}
