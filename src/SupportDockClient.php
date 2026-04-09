<?php

declare(strict_types=1);

namespace SupportDock;

use SupportDock\Exception\SupportDockException;
use SupportDock\Exception\ValidationException;
use SupportDock\Exception\RateLimitException;

class SupportDockClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    /** @var array<string, string> */
    private array $defaultMetadata;

    /**
     * @param array{
     *     apiKey: string,
     *     baseUrl?: string,
     *     timeout?: int,
     *     defaultMetadata?: array<string, string>
     * } $config
     */
    public function __construct(array $config)
    {
        if (empty($config['apiKey'])) {
            throw new ValidationException('apiKey is required');
        }

        $this->apiKey = $config['apiKey'];
        $this->baseUrl = rtrim($config['baseUrl'] ?? 'https://supportdock.io', '/');
        $this->timeout = $config['timeout'] ?? 10;
        $this->defaultMetadata = $config['defaultMetadata'] ?? [];
    }

    /**
     * Submit feedback for the app.
     *
     * @param array{
     *     message: string,
     *     type?: 'bug'|'feature'|'question'|'general',
     *     email?: string,
     *     name?: string,
     *     subject?: string,
     *     metadata?: array<string, string>,
     *     source?: string,
     *     images?: string[]
     * } $options
     * @return array{success: bool}
     * @throws SupportDockException
     */
    public function sendFeedback(array $options): array
    {
        if (empty($options['message'])) {
            throw new ValidationException('message is required');
        }

        if (!empty($options['images'])) {
            if (count($options['images']) > 3) {
                throw new ValidationException('Maximum 3 images allowed');
            }
            foreach ($options['images'] as $image) {
                if (!preg_match('/^data:image\/(png|jpeg|webp|gif);base64,/', $image)) {
                    throw new ValidationException('Images must be base64-encoded data URLs (PNG, JPEG, WebP, or GIF)');
                }
            }
        }

        $metadata = array_merge($this->defaultMetadata, $options['metadata'] ?? []);

        $body = [
            'type' => $options['type'] ?? 'general',
            'message' => $options['message'],
            'source' => $options['source'] ?? 'php-sdk',
        ];

        if (!empty($options['email'])) {
            $body['email'] = $options['email'];
        }
        if (!empty($options['name'])) {
            $body['name'] = $options['name'];
        }
        if (!empty($options['subject'])) {
            $body['subject'] = $options['subject'];
        }
        if (!empty($metadata)) {
            $body['metadata'] = $metadata;
        }
        if (!empty($options['images'])) {
            $body['images'] = $options['images'];
        }

        return $this->request('POST', '/api/v1/feedback/remote', $body);
    }

    /**
     * List all FAQs for the app.
     *
     * @return array<int, array{id: string, question: string, answer: string, sortOrder: int, createdAt: string, updatedAt: string}>
     * @throws SupportDockException
     */
    public function listFAQs(): array
    {
        return $this->request('GET', '/api/v1/faqs/remote');
    }

    /**
     * Create a new FAQ entry.
     *
     * @param array{question: string, answer: string, sortOrder?: int} $options
     * @return array{id: string, appId: string, question: string, answer: string, sortOrder: int, createdAt: string, updatedAt: string}
     * @throws SupportDockException
     */
    public function createFAQ(array $options): array
    {
        if (empty($options['question'])) {
            throw new ValidationException('question is required');
        }
        if (empty($options['answer'])) {
            throw new ValidationException('answer is required');
        }

        return $this->request('POST', '/api/v1/faqs/remote', $options);
    }

    /**
     * Update an existing FAQ entry.
     *
     * @param string $faqId
     * @param array{question?: string, answer?: string, sortOrder?: int} $options
     * @return array{id: string, appId: string, question: string, answer: string, sortOrder: int, createdAt: string, updatedAt: string}
     * @throws SupportDockException
     */
    public function updateFAQ(string $faqId, array $options): array
    {
        if (empty($faqId)) {
            throw new ValidationException('faqId is required');
        }

        return $this->request('PATCH', "/api/v1/faqs/remote/{$faqId}", $options);
    }

    /**
     * Delete a FAQ entry.
     *
     * @param string $faqId
     * @return array{success: bool}
     * @throws SupportDockException
     */
    public function deleteFAQ(string $faqId): array
    {
        if (empty($faqId)) {
            throw new ValidationException('faqId is required');
        }

        return $this->request('DELETE', "/api/v1/faqs/remote/{$faqId}");
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws SupportDockException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'Accept: application/json',
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($body !== null && in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $json = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new SupportDockException(
                'Request failed: ' . ($curlError ?: 'unknown cURL error'),
                0
            );
        }

        $data = json_decode((string) $responseBody, true);

        if ($httpCode >= 400) {
            $message = $data['error'] ?? "HTTP {$httpCode} error";

            if ($httpCode === 429) {
                throw new RateLimitException($message, $httpCode);
            }

            throw new SupportDockException($message, $httpCode);
        }

        return $data ?? [];
    }
}
