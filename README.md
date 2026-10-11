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

In production each geographic region runs its own fully isolated stack (its own
Redis, database, app, and workers) so regional traffic never crosses regions.
This repo **simulates that locally** with `docker compose`: two regions, `us` and
`asia`, each a self-contained stack, behind a single nginx edge proxy that routes
by subdomain.

## Multi-region simulation (Docker)

Everything runs in containers — you do **not** need PHP, Composer, or Redis on the
host to run the simulation (only to run the test suite; see [Testing](#testing)).

### Topology

```
                         http://us.test-horizon.com:8080
                         http://asia.test-horizon.com:8080
                                      |
                                  [ nginx ]              (edge proxy, host :8080)
                              /                \
                   Host: us.*                   Host: asia.*
                        |                             |
        /  ----------- us region -----------   ------------ asia region -----------  \
        |  app-us  (API, php -S :8000)         app-asia  (API, php -S :8000)         |
        |  horizon-us  (queue workers)         horizon-asia  (queue workers)         |
        |  redis-us    (broker, internal)      redis-asia    (broker, internal)      |
        |  db-us       (Postgres, internal)    db-asia       (Postgres, internal)    |
        |  dash-us     (Horizon UI, profile)   dash-asia     (Horizon UI, profile)   |
        \  ---------------------------------   ----------------------------------    /
```

Within a region, nginx routes `/horizon` and `/vendor/horizon` to that region's
dashboard app and everything else to that region's API app. Redis and Postgres
publish **no host ports** — the nginx proxy is the only entry point. Inspect the
data layers with `docker compose exec` (see [below](#inspecting-the-data-layers)).

Each service is named `<service>-<region>` (e.g. `app-us`, `horizon-asia`). Add a
region by copying a regional block in [`compose.yaml`](./compose.yaml) and its
`docker/env/<region>.env`, then adding a `server { … }` block in
[`docker/nginx/default.conf`](./docker/nginx/default.conf).

### 1. Map the region subdomains (`/etc/hosts`)

The proxy routes on the `Host` header, so each subdomain must resolve to your
machine. `/etc/hosts` is root-owned, so edit it with `sudo`:

```bash
# Append both entries in one go (macOS / Linux):
sudo sh -c 'printf "127.0.0.1 us.test-horizon.com\n127.0.0.1 asia.test-horizon.com\n" >> /etc/hosts'
```

Or open it in an editor (`sudo nano /etc/hosts`) and add:

```
127.0.0.1 us.test-horizon.com
127.0.0.1 asia.test-horizon.com
```

Verify with `ping -c1 us.test-horizon.com` (should resolve to `127.0.0.1`). Remove
the two lines later to undo.

### 2. Create `.env`

The containers bind-mount the repo and read non-regional base values (notably
`APP_KEY`) from `.env`. Regional and connection settings are supplied by
`docker/env/*.env` and **override** `.env`, so you only need a valid key:

```bash
cp .env.example .env
php artisan key:generate      # or: docker compose run --rm app-us php artisan key:generate
```

### 3. Start the stack

```bash
docker compose up -d          # builds the PHP image on first run, starts 9 services
docker compose ps             # redis/db should be "healthy"; apps/horizon "running"
```

Each app container runs its migrations on start, then serves the API. The default
stack is **nginx + both regions** (9 services); the two dashboard apps are opt-in
(see [Horizon dashboards](#horizon-dashboards)).

Access each region through the proxy:

| Region | API base | Horizon dashboard |
|---|---|---|
| us | `http://us.test-horizon.com:8080/api` | `http://us.test-horizon.com:8080/horizon` |
| asia | `http://asia.test-horizon.com:8080/api` | `http://asia.test-horizon.com:8080/horizon` |

> The host port is `8080` (host `:80` is commonly taken by other local proxies).
> Change the `nginx` port mapping in `compose.yaml` and the `APP_URL` in
> `docker/env/*.env` together if you want a different port.

### Generating load (watch jobs flow)

The built-in generator POSTs randomized notifications so you can watch jobs move
across the priority queues. Run it **inside** a region's app container, targeting
that container's own server:

```bash
# us region — finite burst of 50 mixed notifications
docker compose exec app-us php artisan notifyhub:simulate --url=http://localhost:8000 --count=50

# asia region — steady ~10 req/s until Ctrl+C
docker compose exec app-asia php artisan notifyhub:simulate --url=http://localhost:8000 --rate=10
```

| Option | Default | Meaning |
|---|---|---|
| `--url` | `config('app.url')` | Target base URL (use `http://localhost:8000` inside a container). |
| `--rate` | `5` | Approximate requests per second. |
| `--count` | `0` | Total requests (`0` = run until Ctrl+C). |
| `--scheduled` | `0.15` | Probability a notification is scheduled 15–60s later. |

Priorities are weighted (bulk most common, critical rarest); bulk notifications
fan out to 20–80 recipients, so a few requests create hundreds of delivery jobs.
The app uses PHP's single-threaded built-in server — fine for a demo, not a
throughput benchmark.

### Inspecting the data layers

Redis and Postgres are internal-only; reach them through the containers:

```bash
# Per-region delivery count (proves isolation: load one region, only its db grows)
docker compose exec db-us   psql -U postgres -d postgres -c 'select count(*) from deliveries;'
docker compose exec db-asia psql -U postgres -d postgres -c 'select count(*) from deliveries;'

# Peek at a region's Redis
docker compose exec redis-us redis-cli -a mypassword keys 'notifyhub*'
```

### Horizon dashboards

Each region's Horizon UI is a **separate, lightweight app** (its own codebase),
scaffolded in `./horizon-dashboard`, so the worker backend and the dashboard scale
and deploy independently — mirroring production. Until that app exists, `/horizon`
returns a graceful `502` (nginx is configured to start regardless).

The dashboard apps are gated behind the `dashboard` compose profile:

```bash
docker compose --profile dashboard up -d      # once ./horizon-dashboard exists
```

**Dashboard env contract.** A dashboard shows a region's jobs only if it reads the
**same Redis, Redis prefix, and Horizon prefix** as that region. The compose
`dash-<region>` services already inject these; the dashboard app must honor them:

| Variable | Value (us / asia) | Why |
|---|---|---|
| `REDIS_HOST` | `redis-us` / `redis-asia` | Point at the region's broker. |
| `REDIS_PASSWORD` | `mypassword` | Matches the broker's `--requirepass`. |
| `REDIS_PREFIX` | `notifyhub:` | Must equal the backend's key prefix. |
| `HORIZON_PREFIX` | `notifyhub_horizon:` | Must equal the backend's Horizon prefix. |
| `HORIZON_NAME` | `us` / `asia` | Labels the dashboard's region. |
| `SESSION_DRIVER`, `CACHE_STORE` | `file` | Dashboard needs no database. |

The dashboard must serve HTTP on port `8000` (nginx proxies `/horizon` and
`/vendor/horizon` to `dash-<region>:8000`).

### Stopping

```bash
docker compose down            # stop; keep data volumes
docker compose down -v         # stop and wipe all per-region data
```

## API

Base path: `/api` on each region subdomain. No authentication. Responses are flat JSON.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/notifications` | Create a notification (queues it for delivery). |
| `GET` | `/api/notifications/{id}` | Status + per-recipient delivery breakdown. |
| `PATCH` | `/api/notifications/{id}` | Update — allowed only while `pending`. |
| `POST` | `/api/notifications/{id}/cancel` | Cancel — allowed only while `pending`. |

`priority` is one of `critical`, `transactional`, `bulk`.

### Example

```bash
# Create (us region)
curl -X POST http://us.test-horizon.com:8080/api/notifications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"message":"Your code is 123456","priority":"critical","recipients":["a@example.com","b@example.com"]}'
# -> 201 {"id":"ntf_...","status":"pending","priority":"critical","recipients_total":2,...}

# Inspect (after Horizon processes it)
curl http://us.test-horizon.com:8080/api/notifications/ntf_... -H 'Accept: application/json'
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

Per-region container settings live in [`docker/env/`](./docker/env/): `common.env`
(shared) plus `us.env` / `asia.env` (regional overrides).

## Testing

The suite runs on the host against an in-memory SQLite database with the `sync`
queue — no Docker services required:

```bash
php artisan test          # full suite (sqlite in-memory, sync queue)
```
