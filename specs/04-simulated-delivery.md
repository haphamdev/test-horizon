# 04 — Simulated Delivery Behavior

> Parent: [notifyhub-high-level.md](./notifyhub-high-level.md) · Status: **Detailed sub-spec**

There is no real email/SMS/push. A delivery job *pretends* to send so that the
queue/worker/retry machinery has realistic work to chew on.

---

## What a delivery job does

1. Mark attempt start (`attempts` increments).
2. Simulate latency — sleep a short, variable amount of time.
3. Decide outcome — succeed or fail based on a configurable failure rate.
4. Record the result:
   - **success** → delivery `sent`, set `sent_at`.
   - **failure** → record `last_error`; retry or mark `failed` per sub-spec 03.

---

## Simulated latency

| Tier | Indicative per-delivery latency |
|---|---|
| critical | short (e.g. ~50–150 ms) |
| transactional | short–moderate |
| bulk | moderate (bulk *feels* slow in aggregate due to volume) |

Exact values are config; the point is that bulk backlogs are visible in Horizon.

---

## Simulated failure

| Parameter | Default | Notes |
|---|---|---|
| Failure rate | small, non-zero (e.g. ~10%) | Per attempt, so retries actually fire. |
| Failure mode | random | Throws/raises a simulated provider error. |
| Error text | canned messages | e.g. "provider timeout", "temporary rejection". |

Failure rate is **configurable** so it can be set to 0% (deterministic success)
for stable tests, or high to exercise the failed-notification path.

---

## Determinism for tests

- The randomness source must be overridable so feature tests can force
  success-only or failure-only runs.
- With failure rate 0, a notification always ends `sent`.
- With failure rate 100 and max attempts exhausted, it always ends `failed`.

---

## Out of scope

- Real provider SDKs/credentials.
- Per-recipient channel selection (email vs SMS) — recipient is an opaque string.
- Delivery receipts / open-tracking.
