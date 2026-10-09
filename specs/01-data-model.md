# 01 — Data Model & States

> Parent: [notifyhub-high-level.md](./notifyhub-high-level.md) · Status: **Detailed sub-spec**

Defines the entities, their fields, and the status transitions. Storage-engine
agnostic (field *types* are indicative, not a schema).

---

## Entities

### Notification (the parent request)

| Field | Type | Notes |
|---|---|---|
| `id` | identifier | Public ID returned to the caller. |
| `message` | text | The body to deliver. |
| `priority` | enum | One of `critical`, `transactional`, `bulk`. |
| `status` | enum | See lifecycle below. Starts `pending`. |
| `scheduled_at` | timestamp, nullable | When it becomes eligible. `null` = send now. |
| `recipients_total` | integer | Count of deliveries fanned out. |
| `recipients_sent` | integer | Deliveries that succeeded. |
| `recipients_failed` | integer | Deliveries permanently failed. |
| `created_at` / `updated_at` | timestamp | Audit. |

### Delivery (one per recipient)

| Field | Type | Notes |
|---|---|---|
| `id` | identifier | Internal. |
| `notification_id` | identifier | Owning notification. |
| `recipient` | string | Target address (fake email/phone/handle). |
| `status` | enum | `pending`, `sent`, `failed`. |
| `attempts` | integer | Delivery attempts made (0..max). |
| `last_error` | text, nullable | Message from the last failed attempt. |
| `sent_at` | timestamp, nullable | When it succeeded. |
| `created_at` / `updated_at` | timestamp | Audit. |

**Relationship:** one Notification *has many* Deliveries. A notification with N
recipients produces N Delivery rows.

---

## Notification Status Lifecycle

```
pending ──► processing ──► sent
   │             │
   │             └────────► failed
   │
   └────────► cancelled
```

| From | To | Trigger |
|---|---|---|
| `pending` | `processing` | Delivery work begins (picked up / scheduled time reached). |
| `pending` | `cancelled` | Caller cancels (allowed only here). |
| `processing` | `sent` | All deliveries reached `sent`. |
| `processing` | `failed` | All deliveries settled and ≥1 is `failed`. |

Terminal states: `sent`, `failed`, `cancelled`. No transitions out of them.

### Status roll-up rule
When every Delivery of a notification is settled (`sent` or `failed`):
- all `sent` → notification `sent`
- otherwise → notification `failed` (with `recipients_failed` > 0)

---

## Delivery Status Lifecycle

```
pending ──► sent
   └──────► failed   (only after attempts exhausted)
```

- A failed attempt that still has retries left keeps the delivery `pending`
  (attempt counter increments; it will be retried).
- `failed` is reached only after `attempts` hits the max (default 3).

---

## Mutability Rules (what can change, when)

| Notification status | `message` / `recipients` / `priority` / `scheduled_at` | `cancel` |
|---|---|---|
| `pending` | editable | allowed |
| `processing` | locked | not allowed |
| `sent` / `failed` / `cancelled` | locked | not allowed |

Editing recipients while `pending` re-derives `recipients_total` (and resets the
not-yet-created delivery set; nothing has been dispatched yet).

---

## Invariants

- `recipients_sent + recipients_failed ≤ recipients_total` at all times.
- Counters are only final once the notification is in a terminal state.
- A `cancelled` notification has **zero** deliveries attempted.
