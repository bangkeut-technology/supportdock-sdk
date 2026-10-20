<?php

declare(strict_types=1);

namespace SupportDock\Monitoring;

/**
 * Groups occurrences of the same error, the way the JS SDK does: the error's
 * class and its top frames' function and file name, never line numbers, which
 * move with every unrelated edit and would split one bug into a group per
 * deploy. Frames inside `vendor/` are skipped, so a bug in your code is one
 * group whichever library call it surfaced through.
 */
final class Fingerprint
{
    private const FRAMES = 5;

    public static function of(\Throwable $error): string
    {
        $frames = [];
        // The throw site first, then the call stack.
        $sites = [['file' => $error->getFile(), 'function' => self::functionAt($error->getTrace()[0] ?? [])]];
        foreach ($error->getTrace() as $i => $frame) {
            $sites[] = [
                'file' => $frame['file'] ?? '',
                'function' => self::functionAt($error->getTrace()[$i + 1] ?? []),
            ];
        }

        foreach ($sites as $site) {
            $file = str_replace('\\', '/', $site['file']);
            if ($file === '' || str_contains($file, '/vendor/')) {
                continue;
            }
            $frames[] = $site['function'] . ' ' . basename($file);
            if (count($frames) === self::FRAMES) {
                break;
            }
        }

        // Without usable frames, fall back to the message with numbers and ids blanked.
        $basis = $frames !== []
            ? implode('|', $frames)
            : (string) preg_replace('/\d+|[0-9a-f]{8,}/i', '#', $error->getMessage());

        return substr(sha1($error::class . '|' . $basis), 0, 14);
    }

    /**
     * The function a frame's file:line sits in is named by the *next* trace
     * entry (PHP records the callee on each frame, and the caller's location).
     *
     * @param array<string, mixed> $frame
     */
    private static function functionAt(array $frame): string
    {
        if (!isset($frame['function'])) {
            return '<main>';
        }

        return isset($frame['class']) ? $frame['class'] . '::' . $frame['function'] : (string) $frame['function'];
    }
}
