# SupportDock PHP SDK

Submit feedback and manage FAQs from your PHP application.

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

## API reference

| Method                                     | Description                                                            |
| ------------------------------------------ | ---------------------------------------------------------------------- |
| `sendFeedback(array $options)`             | Submit feedback (bug, feature, question, general) with optional images |
| `listFAQs()`                               | List all FAQs for the app                                              |
| `createFAQ(array $options)`                | Create a new FAQ entry                                                 |
| `updateFAQ(string $faqId, array $options)` | Update an existing FAQ                                                 |
| `deleteFAQ(string $faqId)`                 | Delete a FAQ entry                                                     |

## License

MIT
