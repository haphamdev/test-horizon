# 02 — API Contract

> Parent: [notifyhub-high-level.md](./notifyhub-high-level.md) · Status: **Detailed sub-spec**

Public, unauthenticated HTTP endpoints. JSON in / JSON out. No pagination/auth
concerns in this phase.

---

## Endpoints Overview

| # | Method | Path | Purpose |
|---|---|---|---|
| E1 | `POST` | `/notifications` | Create a notification. |
| E2 | `PATCH` | `/notifications/{id}` | Update a pending notification. |
| E3 | `POST` | `/notifications/{id}/cancel` | Cancel a pending notification. |
| E4 | `GET` | `/notifications/{id}` | Read status + delivery breakdown. |

---

## E1 — Create

**Request**
```json
{
  "message": "Your code is 123456",
  "priority": "critical",
  "recipients": ["alice@example.com", "bob@example.com"],
  "scheduled_at": null
}
```

**Validation**
- `message` — required, non-empty string.
- `priority` — required, one of `critical` | `transactional` | `bulk`.
- `recipients` — required, array, ≥1 entry, each a non-empty string.
- `scheduled_at` — optional; if present, an ISO-8601 timestamp in the future.

**Response — 201 Created**
```json
{
  "id": "ntf_abc123",
  "status": "pending",
  "priority": "critical",
  "recipients_total": 2,
  "scheduled_at": null,
  "created_at": "2026-10-09T12:00:00Z"
}
```

---

## E2 — Update

**Request** (any subset of the editable fields)
```json
{
  "message": "Updated body",
  "priority": "transactional",
  "recipients": ["carol@example.com"],
  "scheduled_at": "2026-10-10T09:00:00Z"
}
```

**Rules**
- Allowed **only** when status is `pending`.
- Same field validation as E1 for any field supplied.
- Changing `recipients` re-derives `recipients_total`.

**Response — 200 OK** — the updated notification (same shape as E1 response).

---

## E3 — Cancel

**Request** — empty body.

**Rules**
- Allowed **only** when status is `pending`.
- Sets status to `cancelled`; no deliveries occur.

**Response — 200 OK**
```json
{ "id": "ntf_abc123", "status": "cancelled" }
```

---

## E4 — Get status

**Response — 200 OK**
```json
{
  "id": "ntf_abc123",
  "status": "processing",
  "priority": "critical",
  "message": "Your code is 123456",
  "scheduled_at": null,
  "counts": { "total": 2, "sent": 1, "failed": 0, "pending": 1 },
  "deliveries": [
    { "recipient": "alice@example.com", "status": "sent",    "attempts": 1, "sent_at": "2026-10-09T12:00:03Z" },
    { "recipient": "bob@example.com",   "status": "pending", "attempts": 1, "last_error": "provider timeout" }
  ],
  "created_at": "2026-10-09T12:00:00Z",
  "updated_at": "2026-10-09T12:00:03Z"
}
```

---

## Error Responses (shared)

| Case | Status | Body |
|---|---|---|
| Validation failed | `422` | `{ "message": "...", "errors": { "field": ["..."] } }` |
| Notification not found | `404` | `{ "message": "Not found" }` |
| Update/cancel when not `pending` | `409` | `{ "message": "Notification is not editable in its current status." }` |

---

## Non-goals (this phase)

- No list/index endpoint, no filtering, no pagination.
- No auth, rate-limiting, or idempotency keys.
- No webhook/callback on completion (status is pull-only via E4).
