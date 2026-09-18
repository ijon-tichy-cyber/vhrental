# VhRental front-controller API

This document describes the public module front controllers implemented by the
`vhrental` PrestaShop module at commit
`be586b31ede892a429ca6d5d90d9e6e7bb0c7489`.

## Base URL

Production base URL:

```text
https://vanaheim.pl/module/vhrental
```

Controller URLs follow this pattern:

```text
https://vanaheim.pl/module/vhrental/{controller}
```

All requests must use HTTPS.

## Request format

For POST requests, send parameters as either:

- `application/x-www-form-urlencoded` (recommended), or
- `multipart/form-data`.

The current controllers read parameters with PrestaShop `Tools::getValue()`.
They do **not** decode an `application/json` request body.

Every endpoint requires the module API key in a parameter named `key`. It can
be sent in the POST body or query string. Sending it in the body is recommended
because query strings are commonly stored in access logs.

Example headers:

```http
Content-Type: application/x-www-form-urlencoded
Accept: application/json
Cookie: PrestaShop-...=<customer-session-cookie>
```

All responses are JSON encoded as UTF-8:

```http
Content-Type: application/json; charset=utf-8
```

## Authentication

There are two separate authentication requirements:

1. **API key** — required by every endpoint through the `key` parameter.
2. **PrestaShop customer session** — additionally required by `assign` and
   `return`. The application must send the authenticated customer's
   PrestaShop session cookie with the request.

The API does not accept `id_customer`, email, a bearer token, or login/password
in these endpoints. The customer is obtained server-side from:

```php
$this->context->customer->id
```

Consequently, the mobile application must first establish a valid PrestaShop
customer session and preserve its cookie. That login flow is outside the
current `vhrental` controllers.

Keep the API key secret. The current implementation uses one shared module key
and does not provide per-device keys, expiration, rotation through the API, or
rate limiting.

## Endpoint summary

| Purpose | Method | Endpoint | Customer session |
|---|---|---|---|
| Check a box | POST* | `/scan` | No |
| Rent a box | POST | `/assign` | Yes |
| Return a box | POST | `/return` | Yes |
| Mark overdue loans / send notifications | POST* | `/updateoverdue` | No |

---

## POST `/scan`

Returns the current rental state of a box.

### Parameters

| Name | Type | Required | Description |
|---|---:|:---:|---|
| `key` | string | Yes | Shared VhRental API key. |
| `box` | positive integer | Yes | Box ID encoded in the QR code. The ID must exist in the box table. |

### Example request

```bash
curl --request POST 'https://vanaheim.pl/module/vhrental/scan' \
  --header 'Accept: application/json' \
  --data-urlencode 'key=YOUR_API_KEY' \
  --data-urlencode 'box=12'
```

### Success: box is available

HTTP `200 OK`

```json
{
  "ok": true,
  "box": "12",
  "rented": false
}
```

### Success: box is rented

HTTP `200 OK`

```json
{
  "ok": true,
  "box": "12",
  "rented": true,
  "tenant": {
    "firstname": "Anna"
  }
}
```

`box` is currently returned as the received value and may therefore be a JSON
string rather than a number. The only tenant detail currently exposed is the
customer's first name.

### Errors

| HTTP status | Response | Meaning |
|---:|---|---|
| 400 | `{"ok":false,"error":"Brak parametru box."}` | Missing or empty `box`. |
| 400 | `{"ok":false,"error":"Nie ma takiego pudła"}` | `box` is invalid or does not exist. |
| 401 | `{"ok":false,"error":"Unauthorized"}` | Missing or incorrect API key. |

---

## POST `/assign`

Assigns an available box to the customer represented by the current
PrestaShop session.

### Parameters

| Name | Type | Required | Description |
|---|---:|:---:|---|
| `key` | string | Yes | Shared VhRental API key. |
| `box` | positive integer | Yes | Existing box ID encoded in the QR code. |

Do not send `id_customer`; the controller ignores it and uses the authenticated
customer session.

### Example request

```bash
curl --request POST 'https://vanaheim.pl/module/vhrental/assign' \
  --header 'Accept: application/json' \
  --header 'Cookie: PrestaShop-COOKIE_NAME=CUSTOMER_SESSION_VALUE' \
  --data-urlencode 'key=YOUR_API_KEY' \
  --data-urlencode 'box=12'
```

### Success

HTTP `200 OK`

```json
{
  "ok": true,
  "id_loan": 345
}
```

Store `id_loan` if it is useful for local history or diagnostics. Returning a
box uses `box`, not `id_loan`.

### Errors

| HTTP status | Response | Meaning |
|---:|---|---|
| 400 | `{"ok":false,"error":"Wymagany jest parametr box."}` | Missing or empty `box`. |
| 400 | `{"ok":false,"error":"Nie ma takiego pudła"}` | `box` is invalid or does not exist. |
| 401 | `{"ok":false,"error":"Unauthorized"}` | Missing or incorrect API key. |
| 401 | `{"ok":false,"error":"Zaloguj się w Vanaheim"}` | No authenticated PrestaShop customer session. |
| 405 | `{"ok":false,"error":"Użyj metody POST."}` | Request method is not POST. |
| 409 | `{"ok":false,"error":"Skrzynka jest już wypożyczona."}` | Box already has an active or overdue loan. |
| 409 | `{"ok":false,"error":"Nieprawidłowa skrzynka lub klient."}` | Box/customer validation failed. |
| 409 | `{"ok":false,"error":"Klient nie istnieje."}` | Session customer no longer exists. |
| 409 | `{"ok":false,"error":"Nie udało się zapisać wypożyczenia."}` | Database insert failed. |

---

## POST `/return`

Returns a box, but only if its active or overdue loan belongs to the customer
represented by the current PrestaShop session.

### Parameters

| Name | Type | Required | Description |
|---|---:|:---:|---|
| `key` | string | Yes | Shared VhRental API key. |
| `box` | integer | Effectively yes | Box ID to return. A missing/non-numeric value becomes `0` and produces HTTP 404. |

### Example request

```bash
curl --request POST 'https://vanaheim.pl/module/vhrental/return' \
  --header 'Accept: application/json' \
  --header 'Cookie: PrestaShop-COOKIE_NAME=CUSTOMER_SESSION_VALUE' \
  --data-urlencode 'key=YOUR_API_KEY' \
  --data-urlencode 'box=12'
```

### Success

HTTP `200 OK`

```json
{
  "ok": true,
  "id_loan": 345
}
```

### Errors

| HTTP status | Response | Meaning |
|---:|---|---|
| 401 | `{"ok":false,"error":"Unauthorized"}` | Missing or incorrect API key. |
| 401 | `{"ok":false,"error":"Zaloguj się w Vanaheim"}` | No authenticated PrestaShop customer session. |
| 405 | `{"ok":false,"error":"Użyj metody POST."}` | Request method is not POST. |
| 404 | `{"ok":false,"error":"Nie znaleziono aktywnego wypożyczenia."}` | Missing/invalid box, no active or overdue loan, or the loan belongs to another customer. |

---

## POST `/updateoverdue`

Administrative/cron endpoint. It updates overdue statuses and invokes the
notification function.

This endpoint should normally be called by a trusted server-side cron job, not
by the mobile application. It requires the shared API key but no customer
session.

### Parameters

| Name | Type | Required | Description |
|---|---:|:---:|---|
| `key` | string | Yes | Shared VhRental API key. |

### Example request

```bash
curl --request POST 'https://vanaheim.pl/module/vhrental/updateoverdue' \
  --header 'Accept: application/json' \
  --data-urlencode 'key=YOUR_API_KEY'
```

### Success

HTTP `200 OK`

```json
{
  "ok": true,
  "loans_marked_overdue": 3,
  "notifications_sent": 0
}
```

`notifications_sent` is currently always `0`, because notification sending is
a stub in this version.

### Error

| HTTP status | Response | Meaning |
|---:|---|---|
| 401 | `{"ok":false,"error":"Unauthorized"}` | Missing or incorrect API key. |

## Recommended mobile-app flow

1. Establish a PrestaShop customer session and retain the returned cookie.
2. Read the box ID from the QR code.
3. Call `POST /scan`.
4. If `rented` is `false`, offer the user `POST /assign`.
5. If `rented` is `true`, the app may offer `POST /return`; the server will
   succeed only when the loan belongs to the logged-in customer.
6. Treat the HTTP status as authoritative, then inspect `ok` and `error` in the
   JSON response.

## Implementation notes and caveats

- The controllers do not implement the customer login/session creation flow.
- A raw JSON body is not supported.
- There is no API version prefix. Future incompatible changes should introduce
  versioned routes or a version parameter.
- Error messages are currently Polish and should be treated as display text,
  not stable machine-readable error codes.
- `scan` reveals the renter's first name to anyone holding the shared API key.
- The API key is configured in the module's PrestaShop back office. Do not put
  a long-lived shared secret directly in a publicly distributed mobile-app
  binary unless that exposure is accepted; an application backend or scoped,
  revocable mobile tokens would be safer.

