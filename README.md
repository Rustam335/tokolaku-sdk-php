# tokolaku/sdk

Official PHP SDK for the Tokolaku Engine API — AI bot replies, omnichannel messaging (WhatsApp/Instagram/Messenger), and webhook verification.

## Install

```bash
composer require tokolaku/sdk
```

## Requirements

- PHP ≥ 8.1

## Quickstart

*Bahasa Indonesia ringkas: buat instance `Client` dengan API key, lalu panggil `botReply` untuk balasan AI atau `sendMessage` untuk kirim pesan lewat channel resmi (WhatsApp/Instagram/Messenger) yang sudah terhubung.*

```php
<?php

require 'vendor/autoload.php';

use Tokolaku\Client;

$tokolaku = new Client(getenv('TOKOLAKU_API_KEY'));
// or with options: new Client(['api_key' => $apiKey, 'base_url' => $baseUrl, 'timeout' => 30.0, 'max_retries' => 2]);

// 1. AI bot reply for a single customer message
$result = $tokolaku->botReply([
    'message' => 'Halo, apakah produk ini ready stock?',
    'session_id' => 'wa:628123456789', // keeps multi-turn context
]);
echo $result['reply'];

// 2. Send a text message through a connected channel
$sent = $tokolaku->sendMessage([
    'to' => '628123456789',
    'text' => 'Terima kasih sudah menghubungi kami!',
    'channel_id' => 'ch_abc123',
]);
echo $sent['id'] . ' ' . $sent['status'];
```

`sendMessage` also accepts a business-initiated template message — pass `template` instead of `text` (exactly one of the two, never both):

```php
$tokolaku->sendMessage([
    'to' => '628123456789',
    'template' => ['name' => 'order_update', 'language' => 'id', 'category' => 'utility'],
    'channel_id' => 'ch_abc123',
]);
```

## Error handling

Every failed request throws an instance of `Tokolaku\Exception\ApiException` (or one of its subclasses). `getStatus()` returns `null` when the request never got an HTTP response (network error, timeout); `getErrorCode()` returns the backend's machine-readable error code when available.

| Class | HTTP status | When it's thrown |
|---|---|---|
| `ValidationException` | 400, 422 | Invalid request params — also thrown client-side before any network call (e.g. `sendMessage` with both `text` and `template`, or neither) |
| `AuthenticationException` | 401 | Missing or invalid API key |
| `InsufficientBalanceException` | 402 | Tenant balance too low to cover the charge |
| `PermissionException` | 403 | API key lacks permission for this action |
| `RateLimitException` | 429 | Rate limit exceeded |
| `ApiException` | any other status, or `null` | Base class — also covers network errors, timeouts, and malformed responses not mapped above |
| `WebhookSignatureException` | — | Webhook signature missing or invalid (does **not** extend `ApiException`) |

All exception classes live under `Tokolaku\Exception\`.

```php
use Tokolaku\Exception\ApiException;
use Tokolaku\Exception\InsufficientBalanceException;
use Tokolaku\Exception\RateLimitException;

try {
    $tokolaku->botReply(['message' => 'Halo']);
} catch (InsufficientBalanceException $e) {
    // top up balance, notify the tenant
} catch (RateLimitException $e) {
    // back off and retry later
} catch (ApiException $e) {
    error_log($e->getStatus() . ' ' . $e->getErrorCode() . ' ' . $e->getMessage());
}
```

## Retry policy

The SDK retries automatically (`max_retries`, default `2`) using exponential backoff with full jitter (base 250ms, capped at 1s; a `Retry-After` response header wins when present). The `timeout` option is in **seconds** (default `30.0`), matching Guzzle's convention. The policy is **money-aware**: it only retries when a retry cannot cause a duplicate side effect.

| Condition | `botReply` | `sendMessage` |
|---|---|---|
| `429 Too Many Requests` | Retried | Retried |
| Network error (connection failed before any response) | Retried | Retried |
| `5xx` server error | Retried | **Not** retried |
| Timeout (`code: "timeout"`) | **Not** retried | **Not** retried |
| `2xx` with malformed JSON body (`code: "invalid_response"`) | **Not** retried | **Not** retried |
| `2xx` where the body stream fails mid-read (`code: "response_read_error"`) | **Not** retried | **Not** retried |

- `botReply` has no side effect if it fails, so it retries on `429`, any `5xx`, and network errors.
- **`sendMessage` is NOT retried on timeout/5xx because the message may already have been sent** and charged even though the client never saw a successful response, and the API does not yet expose an idempotency key. It only retries on `429` and network errors — a network retry only applies when the connection failed before any response was received (no response headers ever arrived, so nothing could have been sent). Once response headers have arrived, a failure reading the body is a `response_read_error`, not a network error, and is never retried.
- A timeout (`code: "timeout"`) is never retried on either endpoint, since it's ambiguous whether the server received/processed the request.
- A `2xx` response with a body that fails to parse as JSON (`code: "invalid_response"`) carries the actual 2xx status the server returned (usually `200`) and is never retried on either endpoint — the request already reached the server and had its side effect (reply generated / message sent and charged); retrying would risk a double-send or burning AI quota for nothing.
- A `2xx` response whose body stream errors mid-read (`code: "response_read_error"`, e.g. the connection resets after headers arrive) is likewise never retried, for the same reason: response headers arriving means the request already reached the server and may have had its side effect, even though the body was never fully read.

## Webhooks

Verify the `x-tokolaku-signature` header (`sha256=<hex>`, HMAC-SHA256 of the **raw** request body) before trusting a webhook payload. Always use the raw, unmodified request body — a re-serialized JSON string will not match the signature.

```php
use Tokolaku\Webhooks;
use Tokolaku\Exception\WebhookSignatureException;
```

### Laravel

```php
use Illuminate\Http\Request;
use Tokolaku\Webhooks;
use Tokolaku\Exception\WebhookSignatureException;

Route::post('/webhooks/tokolaku', function (Request $request) {
    $rawBody = $request->getContent(); // raw body, do NOT use $request->input()/->json()
    $signature = $request->header('x-tokolaku-signature');

    try {
        $result = Webhooks::constructEvent($rawBody, $signature, config('services.tokolaku.webhook_secret'));
        // ... handle $result['event']
        return response()->json(['received' => true]);
    } catch (WebhookSignatureException $e) {
        return response()->json(['error' => 'invalid signature'], 401);
    }
});
```

### Vanilla PHP

```php
<?php

use Tokolaku\Webhooks;
use Tokolaku\Exception\WebhookSignatureException;

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_TOKOLAKU_SIGNATURE'] ?? null;

try {
    $result = Webhooks::constructEvent($rawBody, $signature, getenv('TOKOLAKU_WEBHOOK_SECRET'));
    // ... handle $result['event']
    echo json_encode(['received' => true]);
} catch (WebhookSignatureException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid signature']);
}
```

## License

MIT

## Docs

Full API reference: [https://tokolaku.id/api-docs](https://tokolaku.id/api-docs)
