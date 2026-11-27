# SupportDock PHP SDK

Submit feedback, manage FAQs and report errors from your PHP application.

SupportDock is a product of [bill&go](https://billandgo.app).

## Requirements

- PHP 8.1+
- `ext-json` and the cURL extension
- zlib (optional, gzips monitoring batches when present)

## Installation

```bash
composer require bangkeut-technology/supportdock-sdk
```

## API keys

Each app has two keys, shown on the app's dashboard in SupportDock:

| Key | Prefix | Can do | Used by |
|---|---|---|---|
| SDK key | `sdk_…` | Submit feedback, read FAQs and the changelog, send monitoring events | `sendFeedback()`, `listFAQs()`, `Monitor` |
| Secret key | `sk_…` | Everything the SDK key does, plus create, update and delete FAQs | `createFAQ()`, `updateFAQ()`, `deleteFAQ()` |

The secret key is shown once, on the app dashboard. Calling a FAQ write with an SDK key returns `403`. Keep the secret key in an environment variable or your secrets store, never in source code. A server-side PHP app can use it, but a key that can change content should not ship in a client-side bundle or a mobile app. Use the SDK key there.

## Usage

### Initialize the client

```php
use SupportDock\SupportDockClient;

$client = new SupportDockClient([
    'apiKey' => getenv('SUPPORTDOCK_SDK_KEY'), // sdk_… for feedback and reading FAQs
    // 'baseUrl' => 'https://supportdock.io',         // optional
    // 'timeout' => 10,                                // seconds, optional
    // 'defaultMetadata' => ['appVersion' => '1.0.0'], // optional
]);
```

| Option | Default | Description |
|---|---|---|
| `apiKey` | — | Required. The constructor throws `ValidationException` when it is empty. |
| `baseUrl` | `https://supportdock.io` | Trailing slashes are removed. |
| `timeout` | `10` | Seconds, for both connecting and the whole request. |
| `defaultMetadata` | `[]` | Merged under each call's `metadata`; a key in the call wins. |

To create or change FAQs, construct a second client with the secret key (`sk_…`):

```php
$admin = new SupportDockClient([
    'apiKey' => getenv('SUPPORTDOCK_SECRET_KEY'),
]);
```

### Send feedback

Requires the SDK key.

```php
$result = $client->sendFeedback([
    'message' => 'App crashes on login page',       // required
    'type' => 'bug',                                // 'bug' | 'feature' | 'question' | 'general' (default 'general')
    'email' => 'user@example.com',                  // optional
    'name' => 'Jane Doe',                           // optional
    'subject' => 'Login crash',                     // optional
    'metadata' => [                                 // optional, string values, merged over defaultMetadata
        'appVersion' => '2.0.0',
        'platform' => 'web',
    ],
    'source' => 'php-sdk',                          // optional, default 'php-sdk'
    'images' => [                                   // optional, up to 3 base64 data URLs (PNG/JPEG/WebP/GIF)
        'data:image/png;base64,iVBOR...',
    ],
    'videos' => [                                   // optional, up to 2 base64 data URLs (MP4/WebM/QuickTime)
        'data:video/mp4;base64,AAAA...',
    ],
    'attachments' => [                              // optional, up to 3 PDFs
        ['name' => 'report.pdf', 'data' => 'data:application/pdf;base64,JVBERi0...'],
    ],
]);
// $result = ['success' => true]
```

`message` is required. Empty `email`, `name` and `subject` are left out of the request. Without a `subject`, the server uses the first line of the message (up to 80 characters).

The SDK checks the counts and data-URL prefixes of `images`, `videos` and `attachments` before sending, and throws `ValidationException` for a violation. It does not check file sizes. The server enforces the per-file limits (images up to 2 MB, videos up to 10 MB, PDFs up to 5 MB).

Feedback is limited by your plan. The Free plan includes 100 inbound feedback messages per month across the account. Once you reach that, the API returns `429` and the SDK throws `RateLimitException`. The Pro plan has no monthly limit. On every plan, each key may send at most 5 submissions per 15 minutes; more also return `429`, with a `Retry-After` header.

#### Attaching images

```php
// Read a local file and convert to base64 data URL
$imageData = file_get_contents('/path/to/screenshot.png');
$base64 = 'data:image/png;base64,' . base64_encode($imageData);

$client->sendFeedback([
    'type' => 'bug',
    'message' => 'UI is broken on this screen',
    'images' => [$base64],
]);
```

#### Attaching videos

```php
// Read a local video and convert to base64 data URL
$videoData = file_get_contents('/path/to/screen-recording.mp4');
$base64 = 'data:video/mp4;base64,' . base64_encode($videoData);

$client->sendFeedback([
    'type' => 'bug',
    'message' => 'Screen recording of the crash attached',
    'videos' => [$base64],
]);
```

#### Attaching PDFs

```php
// Read a local PDF and convert to base64 data URL
$pdfData = file_get_contents('/path/to/report.pdf');
$base64 = 'data:application/pdf;base64,' . base64_encode($pdfData);

$client->sendFeedback([
    'type' => 'bug',
    'message' => 'Steps to reproduce are in the attached report',
    'attachments' => [['name' => 'report.pdf', 'data' => $base64]],
]);
```

### List FAQs

Requires the SDK key.

```php
$faqs = $client->listFAQs();
// Returns a list of FAQs:
// [['id' => '…', 'question' => '…', 'answer' => '…', 'sortOrder' => 1, 'createdAt' => '…', 'updatedAt' => '…'], …]
```

### Create a FAQ

Requires the secret key (`sk_…`).

```php
$faq = $client->createFAQ([
    'question' => 'How do I reset my password?',    // required
    'answer' => 'Go to Settings > Account > Reset Password.', // required
    'sortOrder' => 1,                               // optional
]);
```

`ValidationException` is thrown without a request when `question` or `answer` is empty. The return value is the created FAQ, including its `id` and `appId`.

### Update a FAQ

Requires the secret key (`sk_…`). Sends a `PATCH`, so pass only the fields you change.

```php
$faq = $client->updateFAQ('faq-id-here', [
    'answer' => 'Updated answer text.',
]);
```

Accepts `question`, `answer` and `sortOrder`. `ValidationException` is thrown when the FAQ id is empty.

### Delete a FAQ

Requires the secret key (`sk_…`).

```php
$result = $client->deleteFAQ('faq-id-here');
// $result = ['success' => true]
```

## Error handling

Every method throws one of these, all in the `SupportDock\Exception` namespace:

- `ValidationException` (a `SupportDockException`, status `400`): a missing or invalid argument. Thrown before any request is sent.
- `RateLimitException` (a `SupportDockException`, status `429`): the monthly feedback limit on the Free plan, or more than 5 submissions per key in 15 minutes.
- `SupportDockException`: any other HTTP error (`401`, `403`, `404`, `5xx`, and so on) and network failures. For network failures, `getStatusCode()` returns `0`.

```php
use SupportDock\Exception\SupportDockException;
use SupportDock\Exception\ValidationException;
use SupportDock\Exception\RateLimitException;

try {
    $client->sendFeedback(['message' => 'Bug report']);
} catch (RateLimitException $e) {
    // 429: monthly limit (Free plan) or 5 per 15 minutes
    echo "Rate limited: " . $e->getMessage();
} catch (ValidationException $e) {
    // Invalid input, rejected before sending
    echo "Invalid input: " . $e->getMessage();
} catch (SupportDockException $e) {
    // API or network error
    echo "Error ({$e->getStatusCode()}): " . $e->getMessage();
}
```

## Monitoring (opt-in)

Errors and crashes from your PHP app, shown next to your feedback in SupportDock (app → **Monitoring**, runtime `php`). It authenticates with the app's SDK key in the `x-api-key` header. Nothing is sent until you create a `Monitor`, and the feedback client above is unchanged.

```php
use SupportDock\Monitoring\Monitor;

$monitor = new Monitor([
    'projectKey' => getenv('SUPPORTDOCK_SDK_KEY'),
    'release' => getenv('GIT_SHA') ?: null, // see "Releases" below
]);

// Uncaught exceptions and fatal errors (memory, timeouts), then the queue is
// sent when the script ends. Your previous exception handler still runs.
$monitor->registerHandlers();

try {
    chargeCard($order);
} catch (\Throwable $e) {
    $monitor->captureException($e, ['route' => 'POST /orders']);
    throw $e;
}
```

### Monitor options

| Option | Default | |
|---|---|---|
| `projectKey` | — | Required. Your app's SDK key (`sdk_…`). Missing or empty throws `ValidationException` |
| `release` | auto | Then `SUPPORTDOCK_RELEASE`, `GIT_SHA`, `SOURCE_VERSION`, `GITHUB_SHA`, else `unknown` |
| `beforeSend` | none | `fn (array $event): ?array`: change an event, or return `null` to drop it |
| `ingestUrl` | `https://supportdock.io/ingest` | |
| `timeout` | `2` | Seconds for the send |
| `debug` | `false` | Log delivery failures with `error_log()` |
| `transport` | `CurlTransport` | An object implementing `SupportDock\Monitoring\Transport` (`send(array $events): void`), to send batches your own way |

### Monitor methods

| Method | Description |
|---|---|
| `captureException(\Throwable $error, array $context = [])` | Queue an error you handled. `$context` takes `route`, `screen`, `device` and `os`. |
| `registerHandlers()` | Report uncaught exceptions and fatal errors, and send the queue at shutdown. Call once, early. Repeat calls do nothing. |
| `flush()` | Send everything queued now. Safe to call more than once. |
| `release()` | Return the release string the events are tagged with. |

Events are queued and sent in one batch (gzipped when zlib is available) when the script ends, or when you call `$monitor->flush()`, for example from your framework's terminate hook so it runs after the response. The queue holds up to 1,000 events; when it is full, the oldest event is dropped. A batch that can't be sent is dropped and not retried, and monitoring never throws into your code.

Context is `route`, `screen`, `device` and `os`. `os` is filled with the OS and PHP version when you don't set it. Errors are grouped by class and the top five frames of your own code (`vendor/` skipped), never by line number, so one bug stays one group across deploys.

**Personal data.** An exception message carries whatever the code put in it, SQL parameters included (Doctrine puts them in the message). Scrub messages in `beforeSend` before they leave the process.

**Releases** are what let SupportDock say "this error started in `a1b2c3d`". Set one per deploy, through `release` or one of the environment variables above. The SDK doesn't create deploy markers. To record one in SupportDock, call `POST /api/v1/monitor/deploys` yourself with the secret key (`sk_…`) and a body of `{ "release": "a1b2c3d", "deployedAt": "2026-11-27T10:00:00Z" }` (`deployedAt` is optional).

## API reference

Feedback and FAQ methods are on `SupportDockClient`. The key column shows which key each one needs.

| Method                                     | Key       | Description                                                            |
| ------------------------------------------ | --------- | ---------------------------------------------------------------------- |
| `sendFeedback(array $options)`             | `sdk_`    | Submit feedback (bug, feature, question, general) with optional images, videos, and PDF attachments |
| `listFAQs()`                               | `sdk_`    | List all FAQs for the app                                              |
| `createFAQ(array $options)`                | `sk_`     | Create a new FAQ entry                                                 |
| `updateFAQ(string $faqId, array $options)` | `sk_`     | Update an existing FAQ                                                 |
| `deleteFAQ(string $faqId)`                 | `sk_`     | Delete a FAQ entry                                                     |

`Monitor` methods are listed under [Monitoring](#monitor-methods).

## License

MIT © SupportDock by [bill&go](https://billandgo.app)
