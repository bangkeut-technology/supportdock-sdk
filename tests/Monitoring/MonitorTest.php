<?php

declare(strict_types=1);

namespace SupportDock\Tests\Monitoring;

use PHPUnit\Framework\TestCase;
use SupportDock\Monitoring\Fingerprint;
use SupportDock\Monitoring\Monitor;
use SupportDock\Monitoring\Transport;

final class MonitorTest extends TestCase
{
    private RecordingTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
    }

    /** @param array<string, mixed> $config */
    private function monitor(array $config = []): Monitor
    {
        return new Monitor(['projectKey' => 'sdk_test', 'release' => '1.2.3', 'transport' => $this->transport] + $config);
    }

    public function testCapturedErrorIsQueuedUntilFlush(): void
    {
        $monitor = $this->monitor();
        $monitor->captureException(new \RuntimeException('boom'), ['route' => 'GET /orders/{id}']);

        $this->assertSame([], $this->transport->batches);

        $monitor->flush();
        $monitor->flush();

        $this->assertCount(1, $this->transport->batches);
        $event = $this->transport->batches[0][0];
        $this->assertSame(1, $event['v']);
        $this->assertSame('php', $event['runtime']);
        $this->assertSame('1.2.3', $event['release']);
        $this->assertSame('RuntimeException: boom', $event['message']);
        $this->assertStringStartsWith("RuntimeException: boom\n  in ", $event['stack']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{14}$/', $event['fingerprint']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $event['ts']);
        $this->assertSame('GET /orders/{id}', $event['context']['route']);
        $this->assertStringContainsString('PHP ' . PHP_VERSION, $event['context']['os']);
        $this->assertArrayNotHasKey('fatal', $event);
        $this->assertArrayNotHasKey('native', $event);
    }

    public function testUnknownAndEmptyContextKeysAreDropped(): void
    {
        $monitor = $this->monitor();
        $monitor->captureException(new \RuntimeException('x'), ['route' => '', 'user' => 'jane@example.com', 'device' => 'web-1']);
        $monitor->flush();

        $context = $this->transport->batches[0][0]['context'];
        $this->assertSame(['device', 'os'], array_keys($context));
    }

    public function testLongValuesAreCappedToTheIngestLimits(): void
    {
        $monitor = $this->monitor();
        $monitor->captureException(new \RuntimeException(str_repeat('a', 5000)), ['route' => str_repeat('r', 900)]);
        $monitor->flush();

        $event = $this->transport->batches[0][0];
        $this->assertSame(2000, strlen($event['message']));
        $this->assertLessThanOrEqual(16000, strlen($event['stack']));
        $this->assertSame(512, strlen($event['context']['route']));
    }

    public function testBeforeSendCanRewriteOrDropAnEvent(): void
    {
        $monitor = $this->monitor([
            'beforeSend' => static function (array $event): ?array {
                if (str_contains($event['message'], 'ignore me')) {
                    return null;
                }
                $event['message'] = str_replace('secret', '***', $event['message']);

                return $event;
            },
        ]);
        $monitor->captureException(new \RuntimeException('ignore me'));
        $monitor->captureException(new \RuntimeException('the secret value'));
        $monitor->flush();

        $this->assertCount(1, $this->transport->batches[0]);
        $this->assertSame('RuntimeException: the *** value', $this->transport->batches[0][0]['message']);
    }

    public function testBatchesNeverExceedTheIngestMaximum(): void
    {
        $monitor = $this->monitor();
        for ($i = 0; $i < 1200; ++$i) {
            $monitor->captureException(new \RuntimeException('e' . $i));
        }
        $monitor->flush();

        // The queue keeps the newest 1000, sent as 500 + 500.
        $this->assertSame([500, 500], array_map('count', $this->transport->batches));
        $this->assertSame('RuntimeException: e200', $this->transport->batches[0][0]['message']);
    }

    public function testABrokenTransportNeverThrows(): void
    {
        $monitor = new Monitor(['projectKey' => 'sdk_test', 'transport' => new class implements Transport {
            public function send(array $events): void
            {
                throw new \LogicException('network down');
            }
        }]);
        $monitor->captureException(new \RuntimeException('boom'));
        $monitor->flush();

        $this->addToAssertionCount(1);
    }

    public function testReleaseFallsBackToTheEnvironmentThenUnknown(): void
    {
        $previous = getenv('SUPPORTDOCK_RELEASE');
        try {
            putenv('SUPPORTDOCK_RELEASE=abc1234');
            $this->assertSame('abc1234', (new Monitor(['projectKey' => 'sdk_test', 'transport' => $this->transport]))->release());
            $this->assertSame('v9', (new Monitor(['projectKey' => 'sdk_test', 'release' => 'v9', 'transport' => $this->transport]))->release());

            putenv('SUPPORTDOCK_RELEASE');
            foreach (['GIT_SHA', 'SOURCE_VERSION', 'GITHUB_SHA'] as $name) {
                if (getenv($name) !== false) {
                    $this->markTestSkipped("$name is set in this environment");
                }
            }
            $this->assertSame('unknown', (new Monitor(['projectKey' => 'sdk_test', 'transport' => $this->transport]))->release());
        } finally {
            putenv($previous === false ? 'SUPPORTDOCK_RELEASE' : "SUPPORTDOCK_RELEASE=$previous");
        }
    }

    public function testFingerprintIgnoresLinesButNotTheErrorClass(): void
    {
        $first = $this->thrownAt(1);
        $second = $this->thrownAt(2);

        $this->assertNotSame($first->getLine(), $second->getLine());
        $this->assertSame(Fingerprint::of($first), Fingerprint::of($second));
        $this->assertNotSame(Fingerprint::of($first), Fingerprint::of(new \LogicException('x')));
    }

    private function thrownAt(int $which): \RuntimeException
    {
        try {
            if ($which === 1) {
                throw new \RuntimeException('first');
            }
            throw new \RuntimeException('second');
        } catch (\RuntimeException $e) {
            return $e;
        }
    }
}

final class RecordingTransport implements Transport
{
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    public function send(array $events): void
    {
        $this->batches[] = $events;
    }
}
