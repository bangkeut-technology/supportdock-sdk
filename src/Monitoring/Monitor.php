<?php

declare(strict_types=1);

namespace SupportDock\Monitoring;

/**
 * Error monitoring for PHP apps: errors you capture, and optionally uncaught
 * exceptions and fatal errors, reported to SupportDock (app → Monitoring) with
 * the same API key feedback uses.
 *
 * Events are queued and sent in one batch when the request ends (`flush()`,
 * called by the shutdown handler `registerHandlers()` installs, or by your
 * framework's terminate hook), so reporting adds no network call to a response
 * the client is waiting on. Nothing here throws into the app: a failure to
 * build or send an event is dropped.
 *
 * ```php
 * $monitor = new Monitor(['projectKey' => 'sdk_…', 'release' => getenv('GIT_SHA')]);
 * $monitor->registerHandlers();
 *
 * try { … } catch (\Throwable $e) {
 *     $monitor->captureException($e, ['route' => 'POST /orders']);
 * }
 * ```
 */
final class Monitor
{
    public const RUNTIME = 'php';

    private const DEFAULT_INGEST = 'https://supportdock.io/ingest';
    private const MESSAGE_MAX = 2000;
    private const STACK_MAX = 16000;
    /** The ingest takes at most 500 events per batch. */
    private const BATCH_MAX = 500;
    private const QUEUE_MAX = 1000;
    private const CONTEXT_LIMITS = ['route' => 512, 'screen' => 256, 'device' => 256, 'os' => 128];
    private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private readonly Transport $transport;
    private readonly string $release;
    /** @var (callable(array<string, mixed>): ?array<string, mixed>)|null */
    private $beforeSend;
    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private bool $handlersRegistered = false;
    /** An uncaught exception already reported; PHP then records it as a fatal error too. */
    private bool $crashReported = false;

    /**
     * @param array{
     *     projectKey: string,
     *     release?: string|null,
     *     ingestUrl?: string,
     *     timeout?: int,
     *     debug?: bool,
     *     beforeSend?: callable(array<string, mixed>): ?array<string, mixed>,
     *     transport?: Transport
     * } $config
     *
     * `beforeSend` sees every event before it is queued and returns it (changed
     * or not), or null to drop it. Use it to scrub personal data out of
     * messages: unlike the JS SDK's query instrumentation, a PHP exception
     * message can carry anything the code put in it, SQL parameters included.
     */
    public function __construct(array $config)
    {
        $projectKey = (string) ($config['projectKey'] ?? '');
        $this->release = self::detectRelease($config['release'] ?? null);
        $this->beforeSend = $config['beforeSend'] ?? null;
        $this->transport = $config['transport'] ?? new CurlTransport(
            $config['ingestUrl'] ?? self::DEFAULT_INGEST,
            $projectKey,
            $config['timeout'] ?? 2,
            $config['debug'] ?? false,
        );
    }

    /**
     * Report an error you caught (or your framework handed you).
     *
     * @param array{route?: string, screen?: string, device?: string, os?: string} $context
     */
    public function captureException(\Throwable $error, array $context = []): void
    {
        $this->push($error, $context, null);
    }

    /**
     * Report uncaught exceptions and fatal errors, and send the queue when the
     * script ends. The previous exception handler still runs afterwards, so the
     * app fails exactly as it would have. Call once, early.
     */
    public function registerHandlers(): void
    {
        if ($this->handlersRegistered) {
            return;
        }
        $this->handlersRegistered = true;

        $previous = null;
        $previous = set_exception_handler(function (\Throwable $error) use (&$previous): void {
            $this->crashReported = true;
            $this->push($error, [], false);
            $this->flush();
            if ($previous !== null) {
                $previous($error);
                return;
            }
            // No previous handler: clear ours and rethrow, so PHP prints the
            // uncaught error and exits 255 as it would have. (Rethrowing with
            // ours still installed calls it again, without end.)
            set_exception_handler(null);
            throw $error;
        });

        register_shutdown_function(function (): void {
            $last = error_get_last();
            if (!$this->crashReported && $last !== null && ($last['type'] & self::FATAL_ERRORS) !== 0) {
                $error = new \ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']);
                $this->push($error, [], true);
            }
            $this->flush();
        });
    }

    /** Send everything queued now. Safe to call more than once. */
    public function flush(): void
    {
        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, self::BATCH_MAX);
            try {
                $this->transport->send($batch);
            } catch (\Throwable) {
                // A custom transport broke its contract; still never throw.
            }
        }
    }

    public function release(): string
    {
        return $this->release;
    }

    /**
     * @param array<string, mixed> $context
     * @param bool|null $fatalNative null for a handled error; for a crash,
     *                               whether it came from the engine rather than an exception
     */
    private function push(\Throwable $error, array $context, ?bool $fatalNative): void
    {
        try {
            $event = $this->build($error, $context, $fatalNative);
            if ($this->beforeSend !== null) {
                $event = ($this->beforeSend)($event);
                if ($event === null) {
                    return;
                }
            }
            if (count($this->queue) >= self::QUEUE_MAX) {
                array_shift($this->queue);
            }
            $this->queue[] = $event;
        } catch (\Throwable) {
            // Never let reporting an error raise another one.
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function build(\Throwable $error, array $context, ?bool $fatalNative): array
    {
        $name = $error::class;
        $message = self::truncate($name . ': ' . $error->getMessage(), self::MESSAGE_MAX);
        $stack = self::truncate(
            sprintf("%s\n  in %s:%d\n%s", $message, $error->getFile(), $error->getLine(), $error->getTraceAsString()),
            self::STACK_MAX,
        );

        $event = [
            'v' => 1,
            'ts' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'release' => $this->release,
            'runtime' => self::RUNTIME,
            'fingerprint' => Fingerprint::of($error),
            'message' => $message,
            'stack' => $stack,
            'context' => self::context($context),
        ];
        if ($fatalNative !== null) {
            $event['fatal'] = true;
            $event['native'] = $fatalNative;
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private static function context(array $context): array
    {
        $out = [];
        foreach (self::CONTEXT_LIMITS as $key => $max) {
            if (isset($context[$key]) && is_string($context[$key]) && $context[$key] !== '') {
                $out[$key] = self::truncate($context[$key], $max);
            }
        }
        $out['os'] ??= self::truncate(PHP_OS_FAMILY . ' · PHP ' . PHP_VERSION, 128);

        return $out;
    }

    /** The release the events belong to: config, then the usual CI variables. */
    private static function detectRelease(?string $configured): string
    {
        $candidates = [$configured];
        foreach (['SUPPORTDOCK_RELEASE', 'GIT_SHA', 'SOURCE_VERSION', 'GITHUB_SHA'] as $name) {
            $value = getenv($name);
            $candidates[] = $value === false ? null : $value;
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return self::truncate(trim($candidate), 128);
            }
        }

        return 'unknown';
    }

    /**
     * Caps the byte length, which also caps the character count the ingest
     * checks. A multibyte character cut in half is replaced when encoded.
     */
    private static function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
