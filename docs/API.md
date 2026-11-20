# API reference

Base URL: `http://localhost:8080/api`

All requests and responses are JSON. Send `Accept: application/json` and, for
protected endpoints, `Authorization: Bearer <token>`.

- [Authentication](#authentication)
- [Tickets](#tickets)
- [Status transitions](#status-transitions)
- [Assignment](#assignment)
- [Comments](#comments)
- [Technicians](#technicians)
- [Errors](#errors)

## Authentication

Tokens are Laravel Sanctum personal access tokens. Login and registration are
rate limited to 10 requests per minute per IP.

### `POST /auth/register`

Creates a **requester** account. Technicians cannot be created through the API;
a `role` field in the payload is ignored.

```json
{ "name": "Frank", "email": "frank@example.com", "password": "secret-pass-123", "password_confirmation": "secret-pass-123" }
```

`201 Created`, same body as login.

### `POST /auth/login`

```json
{ "email": "carol@example.com", "password": "password" }
```

`200 OK`

```json
{
  "token": "1|aBcD...redacted",
  "token_type": "Bearer",
  "user": { "id": 3, "name": "Carol Requester", "email": "carol@example.com", "role": "requester" }
}
```

Wrong password and unknown email return the same `422`, so the API does not
reveal which emails have accounts.

### `POST /auth/logout`

Revokes the token used for the request. `204 No Content`.

### `GET /me`

The authenticated user: `{ "data": { "id": 3, "name": "...", "email": "...", "role": "requester" } }`.

## Tickets

### Visibility

| Role | Sees |
| --- | --- |
| requester | Tickets they opened |
| technician | Tickets assigned to them, plus unassigned tickets (the queue) |

A ticket outside your visibility returns **404** on every endpoint, never 403,
so the API does not confirm that someone else's ticket exists.

### `GET /tickets`

| Parameter | Example | Notes |
| --- | --- | --- |
| `status` | `open,in_progress` | Comma-separated: `open`, `in_progress`, `on_hold`, `resolved`, `closed` |
| `priority` | `high,urgent` | Comma-separated: `low`, `medium`, `high`, `urgent` |
| `assignee` | `me` / `none` | Technicians: my tickets / unassigned queue |
| `q` | `vpn` | Case-insensitive search in the title (`%` and `_` are literal) |
| `sort` | `-priority` | `created_at`, `updated_at`, `priority`; `-` prefix for descending. Default `-created_at` |
| `per_page` | `20` | 1 to 100, default 15 |
| `page` | `2` | |

Priority sorts by business weight (urgent > high > medium > low), not alphabetically.

`GET /tickets?status=open,in_progress&sort=-priority&per_page=2` as Carol:

```json
{
  "data": [
    {
      "id": 7,
      "title": "Wi-Fi very slow in meeting room B",
      "description": "Video calls freeze in meeting room B; the rest of the floor is fine.",
      "priority": "urgent",
      "status": "open",
      "allowed_transitions": ["closed"],
      "requester": { "id": 3, "name": "Carol Requester" },
      "assignee": { "id": 1, "name": "Alice Tech" },
      "comments_count": 0,
      "resolved_at": null,
      "closed_at": null,
      "created_at": "2026-10-27T11:34:00.000000Z",
      "updated_at": "2026-10-27T12:34:00.000000Z"
    },
    { "id": 4, "title": "Request access to the finance shared drive", "priority": "high", "status": "open", "assignee": null, "...": "..." }
  ],
  "links": {
    "first": "/api/tickets?status=open%2Cin_progress&sort=-priority&per_page=2&page=1",
    "last": "/api/tickets?status=open%2Cin_progress&sort=-priority&per_page=2&page=3",
    "prev": null,
    "next": "/api/tickets?status=open%2Cin_progress&sort=-priority&per_page=2&page=2"
  },
  "meta": { "current_page": 1, "from": 1, "last_page": 3, "path": "/api/tickets", "per_page": 2, "to": 2, "total": 6 }
}
```

`allowed_transitions` lists what **the current user** may do next, so a client
can show the right buttons without duplicating the business rules.
`comments_count` excludes internal notes for requesters.

### `POST /tickets` (requester)

```json
{ "title": "VPN disconnects every few minutes", "description": "Since this morning...", "priority": "high" }
```

| Field | Rules |
| --- | --- |
| `title` | required, 5 to 150 characters |
| `description` | required, up to 5000 characters |
| `priority` | optional, default `medium` |

`201 Created` with the ticket, status `open`, and its first history entry.
Technicians get `403`.

### `GET /tickets/{id}`

The ticket plus its status `history`:

```json
{
  "data": {
    "id": 19,
    "title": "Request a second monitor",
    "priority": "urgent",
    "status": "resolved",
    "allowed_transitions": ["closed", "in_progress"],
    "requester": { "id": 3, "name": "Carol Requester" },
    "assignee": { "id": 1, "name": "Alice Tech" },
    "history": [
      { "from": null, "to": "open", "by": { "id": 3, "name": "Carol Requester" }, "at": "2026-11-08T16:59:00.000000Z" },
      { "from": "open", "to": "in_progress", "by": { "id": 1, "name": "Alice Tech" }, "at": "2026-11-08T18:59:00.000000Z" },
      { "from": "in_progress", "to": "resolved", "by": { "id": 1, "name": "Alice Tech" }, "at": "2026-11-08T22:59:00.000000Z" }
    ],
    "resolved_at": "2026-11-08T22:59:00.000000Z",
    "closed_at": null,
    "...": "..."
  }
}
```

### `PATCH /tickets/{id}`

Field-level permissions:

| Role | May change | When |
| --- | --- | --- |
| requester (owner) | `title`, `description` | Only while the ticket is `open` |
| technician (assignee) | `priority` | Until the ticket is `closed` |

Sending a field your role cannot change returns `422` (`"Your role cannot change the priority field."`).

## Status transitions

### `POST /tickets/{id}/transitions`

```json
{ "status": "resolved" }
```

| From | To | Who |
| --- | --- | --- |
| `open` | `in_progress` | assigned technician |
| `open` | `closed` | requester (withdraws the ticket) |
| `in_progress` | `on_hold` | assigned technician |
| `in_progress` | `resolved` | assigned technician |
| `on_hold` | `in_progress` | assigned technician |
| `resolved` | `closed` | requester (confirms) or assigned technician |
| `resolved` | `in_progress` | requester (reopens: the fix did not hold) |
| `closed` | | final, no transitions |

Side effects: `resolved` sets `resolved_at`; reopening clears it; `closed` sets
`closed_at`. Every transition is recorded in the history with its author.

A technician who is not the assignee gets `403`. A transition not in the table
gets `422` with the allowed alternatives:

```json
{
  "message": "A technician cannot move a ticket from \"in_progress\" to \"closed\". Allowed: on_hold, resolved.",
  "errors": {
    "status": ["A technician cannot move a ticket from \"in_progress\" to \"closed\". Allowed: on_hold, resolved."]
  }
}
```

## Assignment

### `PUT /tickets/{id}/assignee` (technician)

```json
{ "assignee_id": 1 }
```

- An **unassigned** ticket can be claimed (assign to yourself) or dispatched to any technician.
- A ticket **assigned to you** can be handed over to another technician (you then lose access to it).
- A ticket assigned to a colleague is not visible to you (`404`).
- The assignee must be a technician (`422`); closed tickets cannot be reassigned (`422`).

## Comments

### `GET /tickets/{id}/comments`

Oldest first, 50 per page. Requesters never see internal notes.

```json
{
  "data": [
    { "id": 1, "body": "Looking into this now.", "is_internal": false,
      "author": { "id": 1, "name": "Alice Tech", "role": "technician" }, "created_at": "2026-10-29T14:20:00.000000Z" },
    { "id": 2, "body": "Checked the logs, nothing obvious yet.", "is_internal": true,
      "author": { "id": 1, "name": "Alice Tech", "role": "technician" }, "created_at": "2026-10-29T14:20:00.000000Z" }
  ]
}
```

### `POST /tickets/{id}/comments`

```json
{ "body": "Rebooted the access point, please try again.", "is_internal": false }
```

`is_internal` is technician-only (`422` for requesters). Closed tickets do not
accept comments (`403`). `201 Created`.

## Technicians

### `GET /technicians` (technician)

Technicians with their active workload (open, in progress or on hold), to decide whom to hand a ticket to.

```json
{ "data": [ { "id": 1, "name": "Alice Tech", "active_tickets": 6 }, { "id": 2, "name": "Bob Tech", "active_tickets": 7 } ] }
```

## Errors

| Status | When | Body |
| --- | --- | --- |
| `401` | Missing or invalid token | `{"message": "Unauthenticated."}` |
| `403` | Visible ticket, action not allowed for you | `{"message": "<reason>"}` |
| `404` | Ticket does not exist **or** is not visible to you | `{"message": "Not found."}` |
| `422` | Validation error or invalid transition | `{"message": "...", "errors": {"field": ["..."]}}` |
| `429` | Too many login/registration attempts | `{"message": "Too Many Attempts."}` |
