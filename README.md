# SupportDock PHP SDK

Submit feedback, manage FAQs and report errors from your PHP application.

## Requirements

- PHP 8.1+
- cURL extension

## Installation

```bash
composer require bangkeut-technology/supportdock-sdk
```

## Usage

### Initialize the client

```php
use SupportDock\SupportDockClient;

$client = new SupportDockClient([
    'apiKey' => 'sdk_your_api_key',
    // 'baseUrl' => 'https://supportdock.io',  // optional
    // 'timeout' => 10,                         // seconds, optional
    // 'defaultMetadata' => ['appVersion' => '1.0.0'],  // optional
]);
```

### Send feedback

```php
$result = $client->sendFeedback([
    'type' => 'bug',           // 'bug' | 'feature' | 'question' | 'general'
    'message' => 'App crashes on login page',
    'email' => 'user@example.com',  // optional
    'name' => 'Jane Doe',           // optional
    'subject' => 'Login crash',     // optional, auto-generated if omitted
    'metadata' => [                 // optional
        'appVersion' => '2.0.0',
        'platform' => 'web',
    ],
    'images' => [                   // optional — up to 3 base64 data URLs (PNG/JPEG/WebP/GIF, each ≤ 2 MB)
        'data:image/png;base64,iVBOR...',
    ],
    'videos' => [                   // optional — up to 2 base64 data URLs (MP4/WebM/QuickTime, each ≤ 10 MB)
        'data:video/mp4;base64,AAAA...',
    ],
    'attachments' => [              // optional — up to 3 PDFs (each ≤ 5 MB)
        ['name' => 'report.pdf', 'data' => 'data:application/pdf;base64,JVBERi0...'],
    ],
]);
// $result = ['success' => true]
```

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

```php
$faqs = $client->listFAQs();
// Returns array of FAQ objects
```

### Create a FAQ

```php
$faq = $client->createFAQ([
    'question' => 'How do I reset my password?',
    'answer' => 'Go to Settings > Account > Reset Password.',
    'sortOrder' => 1,  // optional
]);
```

### Update a FAQ

```php
$faq = $client->updateFAQ('faq-id-here', [
    'answer' => 'Updated answer text.',
]);
```

### Delete a FAQ

```php
$result = $client->deleteFAQ('faq-id-here');
// $result = ['success' => true]
```

## Error handling

```php
use SupportDock\Exception\SupportDockException;
use SupportDock\Exception\ValidationException;
use SupportDock\Exception\RateLimitException;

try {
    $client->sendFeedback(['message' => 'Bug report']);
} catch (RateLimitException $e) {
    // 429 — too many requests (5 per 15-minute window)
    echo "Rate limited: " . $e->getMessage();
} catch (ValidationException $e) {
    // Client-side validation failed
    echo "Invalid input: " . $e->getMessage();
} catch (SupportDockException $e) {
    // API error (401, 403, 404, etc.)
    echo "Error ({$e->getStatusCode()}): " . $e->getMessage();
}
```

## Monitoring (opt-in)

Errors and crashes from your PHP app, shown next to your feedback in SupportDock (app → **Monitoring**, runtime `php`). It authenticates with the same API key. Nothing is sent until you create a `Monitor`, and the feedback client above is unchanged.

```php
use SupportDock\Monitoring\Monitor;

$monitor = new Monitor([
    'projectKey' => 'sdk_your_api_key',
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

Events are queued and sent in one batch (gzipped when zlib is available) when the script ends, or when you call `$monitor->flush()`, for example from your framework's terminate hook so it runs after the response. A batch that can't be sent is dropped, and monitoring never throws into your code.

| Option | Default | |
|---|---|---|
| `projectKey` | — | Your app API key (`sdk_…`) |
| `release` | auto | Then `SUPPORTDOCK_RELEASE`, `GIT_SHA`, `SOURCE_VERSION`, `GITHUB_SHA`, else `unknown` |
| `beforeSend` | none | `fn (array $event): ?array`: change an event, or return `null` to drop it |
| `ingestUrl` | `https://supportdock.io/ingest` | |
| `timeout` | `2` | Seconds for the send |
| `debug` | `false` | Log delivery failures with `error_log()` |

Context is `route`, `screen`, `device` and `os` (filled with the OS and PHP version when you don't set it). Errors are grouped by class and the top five frames of your own code (`vendor/` skipped), never by line number, so one bug stays one group across deploys.

**Personal data.** An exception message carries whatever the code put in it, SQL parameters included (Doctrine puts them in the message). Scrub messages in `beforeSend` before they leave the process.

**Releases** are what let SupportDock say "this error started in `a1b2c3d`". Set one per deploy.

## API reference

| Method                                     | Description                                                            |
| ------------------------------------------ | ---------------------------------------------------------------------- |
| `sendFeedback(array $options)`             | Submit feedback (bug, feature, question, general) with optional images, videos, and PDF attachments |
| `listFAQs()`                               | List all FAQs for the app                                              |
| `createFAQ(array $options)`                | Create a new FAQ entry                                                 |
| `updateFAQ(string $faqId, array $options)` | Update an existing FAQ                                                 |
| `deleteFAQ(string $faqId)`                 | Delete a FAQ entry                                                     |

## License

MIT
