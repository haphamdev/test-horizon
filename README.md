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
```

- Horizon dashboard: `http://127.0.0.1:8000/horizon`
- If port `8000` is in use, `php artisan serve` picks the next free port (watch its
  startup line) or set one explicitly: `php artisan serve --port=8001`.

> **Do not use `composer run dev` / `php artisan dev` for this project.** That
> runner starts `queue:listen` on the *default* queue, which does **not** process
> the priority queues and is not Horizon. Run `php artisan horizon` instead.

> Optional: schedule `horizon:snapshot` (every 5 min) to populate the Horizon
> **metrics** graph. Not required for jobs to run.

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
