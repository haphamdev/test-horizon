<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivery Attempts
    |--------------------------------------------------------------------------
    |
    | How many times a single per-recipient delivery job is attempted before it
    | is marked permanently failed, and the backoff (seconds) between attempts.
    |
    */

    'tries' => (int) env('NOTIFYHUB_TRIES', 3),

    'backoff' => [5, 10],

    /*
    |--------------------------------------------------------------------------
    | Simulated Delivery
    |--------------------------------------------------------------------------
    |
    | There is no real provider. Each delivery sleeps for a per-tier latency and
    | randomly fails at the configured rate (0.0 = never, 1.0 = always) so the
    | retry / failure machinery has realistic work to process.
    |
    */

    'delivery' => [
        'failure_rate' => (float) env('NOTIFYHUB_FAILURE_RATE', 0.1),

        'latency_ms' => [
            'critical' => [50, 150],
            'transactional' => [100, 300],
            'bulk' => [200, 600],
        ],
    ],

];
