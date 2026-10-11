# NotifyHub — Specs

A dummy notification-dispatch service for learning Laravel queues (Redis) managed
by Laravel Horizon, with multiple priority queues.

## Documents

| Doc | Scope |
|---|---|
| [notifyhub-high-level.md](./notifyhub-high-level.md) | High-level feature baseline. |
| [01-data-model.md](./01-data-model.md) | Entities, fields, status lifecycles, mutability. |
| [02-api-contract.md](./02-api-contract.md) | Public endpoints: create / update / cancel / status. |
| [03-queues-and-priorities.md](./03-queues-and-priorities.md) | Queue topology, priority guarantee, fan-out, retries. |
| [04-simulated-delivery.md](./04-simulated-delivery.md) | Fake sending: latency + random failure. |
| [05-scheduling.md](./05-scheduling.md) | Optional future-send (deferred from first build). |
| [multi-region-deployment-high-level.md](./multi-region-deployment-high-level.md) | Per-region isolated stacks; local Docker simulation (us + asia). |

## Locked defaults

- Many recipients per notification (bulk fan-out).
- Max 3 delivery attempts, then permanent `failed`.
- Parent is `sent` only if all deliveries succeed; else `failed`.
- Random-failure simulation on (configurable; 0% for stable tests).
- Update/cancel allowed only while `pending`.
- Scheduling documented but deferred.

## Build phases (Track B — not started)

1. **Foundation** — models, migrations, status enums.
2. **API layer** — the 4 public endpoints (synchronous).
3. **Async delivery** — dispatch + fan-out + simulated send + roll-up.
4. **Priorities + retries** — multiple named queues, backoff, failure path.
5. **Horizon + Redis** — Redis queue connection, Horizon supervisors, monitoring.
