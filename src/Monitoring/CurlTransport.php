<?php

declare(strict_types=1);

namespace SupportDock\Monitoring;

/**
 * POSTs a batch to `{ingestUrl}/v1/events/errors`, gzipped when zlib is
 * available, authenticated by the app's API key. A failed send is dropped,
 * never retried: monitoring must not pile work onto an app that is failing.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly string $ingestUrl,
        private readonly string $projectKey,
        private readonly int $timeoutSeconds = 2,
        private readonly bool $debug = false,
    ) {
    }

    public function send(array $events): void
    {
        if ($events === []) {
            return;
        }

        try {
            $json = json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $headers = ['Content-Type: application/json', 'x-api-key: ' . $this->projectKey];
            $body = $json;
            if (function_exists('gzencode')) {
                $gzipped = gzencode($json);
                if ($gzipped !== false) {
                    $body = $gzipped;
                    $headers[] = 'Content-Encoding: gzip';
                }
            }

            $ch = curl_init(rtrim($this->ingestUrl, '/') . '/v1/events/errors');
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($this->debug && ($status < 200 || $status >= 300)) {
                error_log(sprintf('[supportdock] ingest answered %d %s', $status, $error));
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                error_log('[supportdock] could not send errors: ' . $e->getMessage());
            }
        }
    }
}
