# 05 — Scheduling (Optional)

> Parent: [notifyhub-high-level.md](./notifyhub-high-level.md) · Status: **Detailed sub-spec**
> **Deferred from the first build** — document now, implement after the core works.

Lets a caller say "send at a future time" instead of immediately.

---

## Behavior

- `scheduled_at = null` → the notification is eligible immediately on create.
- `scheduled_at` in the future → the notification stays `pending` until that time,
  then becomes eligible and follows the normal fan-out path (sub-spec 03).
- `scheduled_at` in the past (at create/update) → rejected by validation
  (treat as "must be in the future").

---

## Interaction with other rules

- While still `pending` (before the scheduled time), the notification remains
  fully editable and cancellable (sub-spec 01 mutability rules).
- Once the scheduled time is reached and fan-out begins, it is `processing` and
  therefore locked — same as an immediate send.
- Changing `scheduled_at` via update (while `pending`) reschedules it.

---

## Explicitly out of scope

- Recurring schedules (cron-like repetition).
- Time zones beyond the timestamp's own offset (store/compare in UTC).
- Windowed sending / rate-shaped drip campaigns.

---

## Why deferred

The core learning goal (Redis queues + priorities + Horizon) is fully exercised
by immediate sends. Scheduling adds a "becomes eligible later" trigger that is
easy to layer on once the pipeline works end-to-end.
