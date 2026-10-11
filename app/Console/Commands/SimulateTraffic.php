<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('notifyhub:simulate
    {--url= : Base URL of the running app (defaults to config app.url)}
    {--rate=5 : Approximate requests per second}
    {--count=0 : Total requests to send (0 = run until Ctrl+C)}
    {--scheduled=0.15 : Probability (0-1) a notification is scheduled for later}')]
#[Description('Continuously POST randomized notifications to the API to generate Horizon load')]
class SimulateTraffic extends Command
{
    /**
     * Priority tiers with relative weights (bulk is the most common, critical the rarest).
     *
     * @var array<string, int>
     */
    protected array $weights = [
        'bulk' => 50,
        'transactional' => 35,
        'critical' => 15,
    ];

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $rate = max(0.1, (float) $this->option('rate'));
        $max = (int) $this->option('count');
        $scheduledProb = (float) $this->option('scheduled');
        $sleepMicros = (int) (1_000_000 / $rate);

        $running = true;
        $this->trap([SIGINT, SIGTERM], function () use (&$running) {
            $running = false;
        });

        $this->info("Sending ~{$rate} req/s to {$base}/api/notifications".($max > 0 ? " ({$max} total)" : ' (Ctrl+C to stop)'));

        $sent = 0;
        $failed = 0;

        while ($running && ($max === 0 || $sent + $failed < $max)) {
            $payload = $this->payload($scheduledProb);

            try {
                $response = Http::acceptJson()->timeout(5)
                    ->post("{$base}/api/notifications", $payload);

                if ($response->created()) {
                    $sent++;
                    $this->line(sprintf(
                        '<fg=green>✓</> %-13s %3d recipient(s)%s  %s',
                        $payload['priority'],
                        count($payload['recipients']),
                        isset($payload['scheduled_at']) ? ' <fg=yellow>[scheduled]</>' : '',
                        $response->json('id', ''),
                    ));
                } else {
                    $failed++;
                    $this->line("<fg=red>✗</> HTTP {$response->status()}: ".$response->body());
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->line('<fg=red>✗</> '.$e->getMessage());
            }

            usleep($sleepMicros);
        }

        $this->newLine();
        $this->info("Done. created={$sent} failed={$failed}");

        return self::SUCCESS;
    }

    /**
     * Build a randomized create-notification payload.
     *
     * @return array<string, mixed>
     */
    protected function payload(float $scheduledProb): array
    {
        $priority = $this->weightedPriority();

        $count = match ($priority) {
            'bulk' => random_int(20, 80),
            'transactional' => random_int(1, 5),
            default => random_int(1, 3),
        };

        $payload = [
            'message' => "Simulated {$priority} notification",
            'priority' => $priority,
            'recipients' => array_map(
                fn (int $i): string => 'user'.random_int(1, 100_000).'@example.com',
                range(1, $count),
            ),
        ];

        if (mt_rand() / mt_getrandmax() < $scheduledProb) {
            $payload['scheduled_at'] = now()->addSeconds(random_int(15, 60))->toISOString();
        }

        return $payload;
    }

    protected function weightedPriority(): string
    {
        $roll = random_int(1, array_sum($this->weights));

        foreach ($this->weights as $priority => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $priority;
            }
        }

        return 'bulk';
    }
}
