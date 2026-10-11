# NotifyHub

A dummy notification-dispatch service for learning **Laravel queues on Redis,
managed by Laravel Horizon**, with multiple priority queues.

Submit a notification over a public HTTP API; it is delivered asynchronously in
the background. Urgent notifications (`critical`) always beat bulk ones
(`bulk`) — a 10,000-recipient blast must never delay a password reset.

> Functional specs live in [`specs/`](./specs/README.md).

## Architecture

- **API** — public JSON endpoints to create / update / cancel / inspect notifications.
- **Queues** — three Redis queues by priority: `critical`, `transactional`, `bulk`.
- **Workers** — Horizon drains the queues in strict priority order; each recipient
  is an independent delivery job with retries and a simulated (flaky) provider.

## Requirements

- PHP 8.3+ with the `redis` (phpredis) extension
- Composer, Node.js
- Docker (for the Postgres + Redis containers)

## Setup

1. **Start Postgres + Redis** (defined in [`compose.yaml`](./compose.yaml)):

   ```bash
   docker compose up -d
   docker compose ps          # both services should be "healthy"
   ```

2. **Create your `.env`** and generate a key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Ports and credentials come from `.env` and are honored by `compose.yaml`:

   | Setting | Default (`.env.example`) | Notes |
   |---|---|---|
   | `DB_PORT` | `5432` | Host port → container `5432`. |
   | `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `postgres` / `postgres` / `mysecretpassword` | |
   | `REDIS_PORT` | `6380` | Host port → container `6379`. |
   | `REDIS_PASSWORD` | `mypassword` | Matches the Redis container's `--requirepass`. |
   | `QUEUE_CONNECTION` | `redis` | |

   > Change a port in `.env`, then `docker compose up -d` again to recreate the
   > container with the new mapping. Nothing else needs editing — one value drives
   > both the container's host port and Laravel's connection.

3. **Migrate:**

   ```bash
   php artisan migrate
   ```

## Running the app

Run two processes in separate terminals from the project root:

```bash
# Terminal 1 — HTTP API
php artisan serve                 # http://127.0.0.1:8000

# Terminal 2 — queue workers (Horizon)
php artisan horizon               # drains critical -> transactional -> bulk

# Terminal 3 — scheduler (only needed for the Horizon metrics graphs)
php artisan schedule:work
```

- Horizon dashboard: `http://127.0.0.1:8000/horizon`
- If port `8000` is in use, `php artisan serve` picks the next free port (watch its
  startup line) or set one explicitly: `php artisan serve --port=8001`. The
  dashboard lives on whatever port `serve` actually bound (e.g. `:8001/horizon`),
  since `/horizon` is served by the app — `php artisan horizon` has no web port.

> **Do not use `composer run dev` / `php artisan dev` for this project.** That
> runner starts `queue:listen` on the *default* queue, which does **not** process
> the priority queues and is not Horizon. Run `php artisan horizon` instead.

### Metrics graphs ("Not Enough Data")

The per-queue **Metrics** pages (`/horizon/metrics/queues/{queue}`) are built from
periodic *snapshots*, not live data. Running `php artisan horizon` alone records
nothing on those graphs — you need `horizon:snapshot` to run on a schedule.

- It's already registered in [`routes/console.php`](./routes/console.php):
  `Schedule::command('horizon:snapshot')->everyFiveMinutes();`
- Run `php artisan schedule:work` (Terminal 3) so it actually fires. In production,
  a system cron runs `php artisan schedule:run` every minute instead.
- The graph shows **"Not Enough Data"** until at least two snapshots exist, so give
  it ~10 minutes of traffic. It fills out to 24 points (~2 hours) over time.
- For **immediate** feedback that needs no snapshots, watch the Horizon dashboard's
  **Current Workload** (pending jobs + processes + wait time per queue) and the
  **Recent/Failed Jobs** pages while you generate load.

## Generating load (watch Horizon)

A built-in load generator POSTs randomized notifications to the API so you can
watch jobs flow across the priority queues on the Horizon dashboard — mixed
tiers, large bulk fan-outs, some scheduled (delayed) notifications, and retries.

```bash
# With serve running on 8001 (adjust --url to your port):
php artisan notifyhub:simulate --url=http://127.0.0.1:8001 --rate=10

# Finite burst, more scheduled jobs:
php artisan notifyhub:simulate --url=http://127.0.0.1:8001 --count=50 --scheduled=0.3
```

| Option | Default | Meaning |
|---|---|---|
| `--url` | `config('app.url')` | Base URL of the running app. |
| `--rate` | `5` | Approximate requests per second. |
| `--count` | `0` | Total requests (`0` = run until Ctrl+C). |
| `--scheduled` | `0.15` | Probability a notification is scheduled for 15–60s later. |

Priorities are weighted (bulk most common, critical rarest); bulk notifications
fan out to 20–80 recipients, so a few requests create hundreds of delivery jobs.
Prefer an off-the-shelf tool for raw throughput? `oha`, `hey`, `vegeta`, or `k6`
work too, but they send a fixed body — this command varies the payload.

## API

Base path: `/api`. No authentication. Responses are flat JSON.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/notifications` | Create a notification (queues it for delivery). |
| `GET` | `/api/notifications/{id}` | Status + per-recipient delivery breakdown. |
| `PATCH` | `/api/notifications/{id}` | Update — allowed only while `pending`. |
| `POST` | `/api/notifications/{id}/cancel` | Cancel — allowed only while `pending`. |

`priority` is one of `critical`, `transactional`, `bulk`.

### Example

```bash
# Create
curl -X POST http://127.0.0.1:8000/api/notifications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"message":"Your code is 123456","priority":"critical","recipients":["a@example.com","b@example.com"]}'
# -> 201 {"id":"ntf_...","status":"pending","priority":"critical","recipients_total":2,...}

# Inspect (after Horizon processes it)
curl http://127.0.0.1:8000/api/notifications/ntf_... -H 'Accept: application/json'
# -> {"status":"sent","counts":{"total":2,"sent":2,"failed":0,"pending":0},"deliveries":[...]}
```

Optional `"scheduled_at"` (future ISO-8601 timestamp) delays delivery.

## Lifecycle

```
pending -> processing -> sent
                      \-> failed      (>=1 delivery failed after retries)
pending -> cancelled
```

A notification settles once every per-recipient delivery settles: `sent` if all
succeeded, otherwise `failed`.

## Configuration

[`config/notifyhub.php`](./config/notifyhub.php):

- `tries` — delivery attempts before permanent failure (default `3`; env `NOTIFYHUB_TRIES`).
- `backoff` — seconds between retries (default `[5, 10]`).
- `delivery.failure_rate` — simulated failure probability `0.0`–`1.0`
  (default `0.1`; env `NOTIFYHUB_FAILURE_RATE`). Set `0` for guaranteed success.
- `delivery.latency_ms` — simulated per-tier send latency.

Priority queues and worker topology: [`config/horizon.php`](./config/horizon.php)
(`supervisor-1` drains `['critical','transactional','bulk']` with `balance => false`
for strict priority).

## Testing

```bash
php artisan test          # full suite (sqlite in-memory, sync queue)
```
