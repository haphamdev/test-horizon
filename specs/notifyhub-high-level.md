# NotifyHub — High-Level Feature Spec

> Status: **High-level baseline.** To be broken down into detailed specs later.

Functional spec only — no technical solution yet. Sensible defaults chosen for open
questions; each is flagged so it can be vetoed.

---

## 1. Purpose

Accept notification requests over public HTTP endpoints and deliver them
asynchronously, honoring priority so urgent notifications always beat bulk ones.

---

## 2. Core Concepts

| Concept | Meaning |
|---|---|
| **Notification** | One delivery request: a message, a priority tier, and one or more recipients. |
| **Recipient** | A single target address (just a string — e.g. a fake email/phone). No user accounts. |
| **Delivery** | The act of sending to *one* recipient. A notification with N recipients produces N deliveries. |
| **Priority tier** | `critical`, `transactional`, or `bulk` — determines dispatch urgency. |

> **Default:** a notification can target **many** recipients (enables bulk fan-out).
> One-recipient requests are just N=1.

---

## 3. Priority Tiers (the core business rule)

| Tier | Example use | Dispatch rule |
|---|---|---|
| **critical** | security alert, password reset | Always sent first; never waits behind others. |
| **transactional** | order confirmation, receipt | Sent after critical is clear. |
| **bulk** | newsletter, marketing blast | Sent only with spare capacity; must **never** delay critical/transactional. |

**Invariant:** a 10,000-recipient bulk blast must not delay a single critical notification.

---

## 4. Notification Lifecycle (status model)

```
pending → processing → sent
                     ↘ failed
pending → cancelled
```

- **pending** — accepted, not yet picked up for delivery.
- **processing** — being delivered (some deliveries may still be in flight).
- **sent** — all deliveries succeeded.
- **failed** — one or more deliveries permanently failed after retries.
- **cancelled** — cancelled by the user while still pending.

> **Default:** per-recipient deliveries each track their own outcome; the parent
> notification is `sent` only if **all** succeed, otherwise `failed` (with a count
> of how many failed).

---

## 5. Features

### F1 — Create a notification
Submit: message body, priority tier, recipient list, (optional) scheduled send time.
→ Returns an ID and initial status `pending`.

### F2 — Update a notification
Edit message / recipients / priority / scheduled time — **only while `pending`**.
Once `processing`, it's locked.

### F3 — Cancel a notification
Cancel while `pending` → status `cancelled`, no deliveries happen.

### F4 — Check status
Fetch a notification by ID: its status, per-recipient delivery outcomes, counts
(sent / failed / pending), timestamps.

### F5 — Asynchronous delivery
After acceptance, delivery happens in the background. Each recipient is delivered
independently so one bad recipient doesn't block the rest.

### F6 — Retry on failure
Deliveries simulate a flaky provider (random occasional failures). Failed deliveries
retry a bounded number of times before being marked permanently failed.

> **Default:** up to **3 attempts** per delivery, then permanent `failed`.

### F7 — Scheduled sending *(optional, nice-to-have)*
Allow "send at a future time." At that time the notification becomes eligible for
delivery.

> **Default:** include **send-now + optional future timestamp**; no recurring schedules.

---

## 6. Simulated Delivery Behavior

No real email/SMS. A delivery "sends" by pretending:
- Takes a short, variable amount of time (bulk feels slow).
- Randomly fails a small % of the time → exercises retries.
- On success/failure, records the outcome and timestamp.

---

## 7. Explicitly Out of Scope (for now)

- Authentication / users / tenancy.
- Real delivery providers (email/SMS/push).
- Analytics/reporting dashboards.
- Rate-limiting per recipient, deduplication, templating.

---

## 8. Open Decisions (defaults applied — confirm or override)

| # | Decision | Default |
|---|---|---|
| 1 | Recipients per notification | Many (bulk allowed) |
| 2 | Max retry attempts | 3 |
| 3 | Scheduled sends | Yes, optional future timestamp |
| 4 | Parent status when some deliveries fail | `failed` + failed-count |
| 5 | Random-failure simulation | On (so retries are observable) |
| 6 | Update allowed when | Only while `pending` |

---

## 9. Next Steps

- Confirm/override the open decisions in section 8.
- Break this high-level spec into detailed feature specs.
- Separately, design the technical solution (Redis-backed queues, multiple
  priority queues, Laravel Horizon, worker topology).
