# Multi-Region Horizon Deployment Simulation — High-Level Spec

> Status: **High-level baseline.** To be broken down section-by-section later.
> This is an infrastructure/repo-shape change, not a business-feature spec.

Two independent workstreams, deliverable in order:

- **Workstream A — Backend-only cutover:** strip all UI/frontend code so the repo
  is a pure backend API + queue service.
- **Workstream B — Local multi-region simulation:** a `docker compose` topology
  that mirrors the target per-region production layout, reachable via subdomains.

---

## 1. Purpose

Simulate, on one developer machine, how the app is deployed across **multiple
regions**, where each region is fully self-contained (its own Redis, DB, app,
workers, and a dedicated Horizon dashboard). The simulation must make the
region-isolation model observable: each dashboard shows **only** its own region's
workers.

Secondarily, reduce the repo to backend concerns only — no Inertia/Vue/Vite — since
we only work with backend endpoints.

---

## 2. Target Production Model (what we are mirroring)

Per region, `N` regions total:

| Role | Responsibility |
|---|---|
| **App server** | Laravel; serves user HTTP/API requests; enqueues jobs to the region's Redis (`QUEUE_CONNECTION=redis`). |
| **Horizon server** | `php artisan horizon`; workers drain the region's Redis queues (`critical` → `transactional` → `bulk`); executes `App\Jobs\*`, which hit the region's DB. |
| **Redis server** | Region-local queue broker **and** Horizon metadata store. |
| **Dashboard app** | A **separate, lightweight** Laravel app serving **only** `/horizon`; no business routes, no job enqueuing. Points at the **same Redis + same Horizon prefix** as the region's app/horizon so it reflects that region's workers. |

Multiple regions ⇒ multiple Redis instances, multiple independent Horizon backends,
multiple dashboards. Regions share **nothing** at runtime; a dashboard can only ever
see its own region because Redis is isolated per region.

**Core invariant:** a Horizon dashboard reflects a region's workers **iff** it shares
that region's Redis connection **and** `HORIZON_PREFIX`. Same Redis + same prefix =
same backend view.

---

## 3. Workstream A — Backend-Only Cutover

Goal: remove every UI/frontend concern. Keep the API, queue, Horizon-backend, and
test surfaces fully working. Clean cutover — no shims, no dead config.

### A1 — Delete frontend source
- `resources/js/` (entire tree: `app.ts`, `pages/`, `lib/`, `types/`, generated
  `routes/`, `actions/`, `wayfinder/`).
- `resources/css/`.
- `resources/views/app.blade.php` (the Inertia root template); remove the now-empty
  `resources/views/` if nothing else lives there.
- `bootstrap/ssr/` if present.

### A2 — Delete JS/build tooling
- `vite.config.ts`, `tsconfig.json`, `package.json`, `package-lock.json`,
  `pnpm-workspace.yaml`, `.npmrc`.

### A3 — Remove Inertia + Wayfinder from the backend
- `app/Http/Middleware/HandleInertiaRequests.php`.
- `config/inertia.php`.
- Composer deps: drop `inertiajs/inertia-laravel` and `laravel/wayfinder`
  (**keep** `laravel/horizon`, `laravel/tinker`).
- `bootstrap/app.php`: remove the `HandleInertiaRequests` and
  `AddLinkHeadersForPreloadedAssets` entries from the `web` middleware append
  (the latter only serves preloaded Vite assets).

### A4 — Routes
- `routes/web.php`: remove `Route::inertia('/', 'Welcome')`. Leave `web.php` minimal
  (empty, or a trivial JSON/redirect). `/up` health and `/api/*` remain the live
  surfaces; Horizon registers its own routes via its service provider.
- Consequence: `GET /` returns 404 on the app container (intentional — no UI).

### A5 — Tests & tooling
- `tests/Feature/ExampleTest.php` pins `route('home')`; delete it (scaffold test for
  the removed FE route) rather than re-pin it.
- Composer scripts: strip `npm install` / `npm run build` from `setup`; strip
  `npm run check` / `npm run types:check` from `ci:check`. Reconcile the `dev`
  script (currently `php artisan dev`, which starts Vite) — no longer meaningful.
- Audit `.github/workflows/` for Node/npm steps and remove them.
- `.env` / `.env.example`: remove `VITE_APP_NAME`.

### A6 — Acceptance
- `composer install` with no Node toolchain present succeeds.
- `php artisan test` passes (API/model/delivery/schedule suites green; no FE test).
- `php artisan serve` boots; `/api/notifications` works; `/horizon` still renders
  from the backend package; `/` is 404.

> **Note:** removing the FE moots the earlier "bake `npm run build` into the app
> image" decision — the Docker image (Workstream B) becomes **PHP-only**.

---

## 4. Workstream B — Local Multi-Region Simulation

Regions for the local sim: **`us`** and **`asia`** (2 regions).

### B1 — Container topology (11 services)

| Role | us | asia | Depends on | Notes |
|---|---|---|---|---|
| Redis | `redis-us` | `redis-asia` | — | isolated brokers + Horizon store |
| Postgres | `db-us` | `db-asia` | — | shared by region app + horizon |
| App (user requests) | `app-us` | `app-asia` | region db + redis | `php artisan serve`; migrates on boot |
| Horizon backend | `horizon-us` | `horizon-asia` | region db + redis | `php artisan horizon` |
| Horizon dashboard | `dash-us` | `dash-asia` | region redis | **placeholder** `build: ./horizon-dashboard` |
| Reverse proxy | `nginx` (single, shared) | | all app/dash | Host + path routing on `:80` |

### B2 — Image strategy
- **One PHP-only image** (PHP 8.3+ with the `phpredis` extension, `composer install
  --no-dev` as appropriate), reused for `app-*` and `horizon-*`. Role differs only by
  command + env.
- `dash-*` reference a **placeholder** `build: ./horizon-dashboard`. The real
  dashboard is a separate Laravel app the owner scaffolds later (Laravel installer);
  this spec only reserves the compose service + wiring, not the app itself.

### B3 — Per-region env wiring (the invariant, concretely)
For each region, `app-<r>`, `horizon-<r>`, `dash-<r>` share:
- `REDIS_HOST=redis-<r>` (and matching port/password).
- An explicit, identical `HORIZON_PREFIX` (e.g. `notifyhub_us_horizon:` /
  `notifyhub_asia_horizon:`).
- `HORIZON_NAME=us` / `asia` so the dashboard UI labels the region.

`app-<r>` and `horizon-<r>` additionally share `DB_HOST=db-<r>`. The dashboard runs
**DB-free**: `SESSION_DRIVER=file`, `CACHE_STORE=file` (current defaults are
`database`, which would otherwise force a Postgres dependency just to boot).

### B4 — Routing & hostnames (Scheme A: one host per region, path-split)
Owner adds to `/etc/hosts`:
```
127.0.0.1 us.test-horizon.com asia.test-horizon.com
```
`nginx` on `:80` routes by Host header + path:

| URL | → container |
|---|---|
| `us.test-horizon.com/`, `/api/*`, `/up` | `app-us` |
| `us.test-horizon.com/horizon` (+ Horizon assets/api) | `dash-us` |
| `asia.test-horizon.com/`, `/api/*`, `/up` | `app-asia` |
| `asia.test-horizon.com/horizon` (+ Horizon assets/api) | `dash-asia` |

A proxy is required even with `/etc/hosts`: both names resolve to `127.0.0.1:80`, so
differentiation happens on the Host header. `/horizon` and its sub-paths
(`/horizon/api/*`, published assets) route to the dashboard; everything else routes
to the app.

### B5 — Boot/ordering
- App/horizon containers wait for their region's DB + Redis healthchecks.
- App container runs `php artisan migrate --force` on start (one region = one DB).
- Each region's Horizon backend produces live supervisor/worker data in its Redis;
  the matching dashboard renders it.

### B6 — Acceptance
- `docker compose up -d` brings all 11 services healthy.
- `POST us.test-horizon.com/api/notifications` enqueues; `us.test-horizon.com/horizon`
  shows those jobs/workers.
- The **same** request to `asia.*` is invisible on the `us` dashboard and vice
  versa (region isolation demonstrated).
- Stopping `horizon-us` empties the `us` dashboard's active workers without
  affecting `asia`.

---

## 5. Explicitly Out of Scope

- Building the real dashboard Laravel app (owner scaffolds it later; we leave the
  compose placeholder + env contract).
- TLS/HTTPS, real DNS, production secrets management.
- Horizontal scaling beyond the fixed 2-region sim, auto-provisioning more regions.
- `nginx` + php-fpm or Octane/FrankenPHP serving (chosen: `php artisan serve`).
- Any business-logic change — Workstream A is deletion only, not behavior change.

---

## 6. Locked Decisions (from review)

| # | Decision | Choice |
|---|---|---|
| 1 | Dashboard app shape | Separate Laravel app in `./horizon-dashboard`; compose placeholder only |
| 2 | Local DNS | `/etc/hosts` entries for `us`/`asia.test-horizon.com` |
| 3 | Reverse proxy | `nginx`, single shared container, explicit conf |
| 4 | Routing scheme | A — one host per region, `/horizon` path-split to dashboard |
| 5 | Serving stack | `php artisan serve` per app/dashboard container |
| 6 | App image | Single PHP-only image for `app-*` + `horizon-*` (no Node) |
| 7 | Frontend | Fully removed (Inertia + Vue + Vite + Wayfinder) |
| 8 | Regions (local) | `us`, `asia` |
| 9 | Dev tooling | Drop `dev` script + `laravel/pao` + `laravel/sail`; keep `laravel/pail` |

---

## 7. Next Steps

- Review this baseline section-by-section.
- Break Workstream A into an ordered deletion/edit checklist (keep the test suite
  green at each step).
- Break Workstream B into: Dockerfile → compose services → env matrix → nginx conf
  → `/etc/hosts` + run/verify docs.
- Decide whether to update `README.md` and `specs/README.md` to reflect the new
  backend-only, multi-region run model (doc step, after implementation).
