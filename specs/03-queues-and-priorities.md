# 03 — Queues & Priorities

> Parent: [notifyhub-high-level.md](./notifyhub-high-level.md) · Status: **Detailed sub-spec**

Defines the queue topology, the priority guarantee, fan-out, and retry/failure
policy. Expressed as behavior/rules; concrete Horizon/Redis wiring is deferred to
the technical-design phase.

---

## Queues

Three named queues, one per priority tier:

| Queue | Tier | Intended drain behavior |
|---|---|---|
| `critical` | critical | Drained first; highest worker share. |
| `transactional` | transactional | Drained after critical is clear. |
| `bulk` | bulk | Drained only with spare capacity. |

**The guarantee:** work in `critical` is always processed ahead of
`transactional`, which is always ahead of `bulk`. A large `bulk` backlog must
never delay a `critical` job.

> How this guarantee is realized (strict queue ordering vs. dedicated worker
> pools per queue) is a technical-design decision — see Track B, phase 5.

---

## Fan-out (1 notification → N delivery jobs)

On a notification becoming eligible:
1. The notification transitions `pending → processing`.
2. One **delivery job per recipient** is enqueued onto the queue matching the
   notification's priority.
3. Each delivery job is independent: one recipient failing never blocks another.

Rationale: per-recipient jobs keep bulk sends parallelizable and make retries
granular (retry one recipient, not the whole blast).

---

## Retry & Backoff Policy

| Parameter | Default | Notes |
|---|---|---|
| Max attempts per delivery | **3** | After the 3rd failed attempt → delivery `failed`. |
| Backoff between attempts | increasing | e.g. a few seconds, growing per attempt. |
| Retry stays on | same queue | A retried `critical` delivery stays `critical`. |

A failed attempt with retries remaining:
- increments `attempts`,
- records `last_error`,
- leaves the delivery `pending` for another attempt.

A failed attempt with **no** retries remaining:
- sets the delivery to `failed`,
- records `last_error`.

---

## Permanent Failure Handling

- A delivery that exhausts retries is terminal `failed` and increments the
  parent's `recipients_failed`.
- The parent notification settles (per the roll-up rule in sub-spec 01) once
  **all** deliveries are terminal.
- Permanently failed jobs should be inspectable (a failed-job record) rather
  than silently dropped.

---

## Status Roll-up Trigger

After each delivery settles (`sent` or `failed`), check whether all of the
notification's deliveries are settled. If so:
- all `sent` → notification `sent`
- otherwise → notification `failed`

This check must be safe under concurrency (many delivery jobs finishing at once).

---

## Open technical questions (deferred to Track B)

- Strict priority ordering on one worker vs. separate supervisors per queue.
- Worker concurrency per queue (how much capacity bulk may borrow).
- Whether roll-up is done per-delivery or via a periodic reconciler.
