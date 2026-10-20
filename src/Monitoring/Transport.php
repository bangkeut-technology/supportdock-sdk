<?php

declare(strict_types=1);

namespace SupportDock\Monitoring;

/**
 * Delivers one batch of events to the ingest. Implementations must not throw:
 * monitoring drops a batch it cannot send rather than failing the app.
 */
interface Transport
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function send(array $events): void;
}
