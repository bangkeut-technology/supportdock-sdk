<?php

declare(strict_types=1);

namespace SupportDock\Exception;

class RateLimitException extends SupportDockException
{
    public function __construct(string $message = 'Rate limit exceeded', int $statusCode = 429, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }
}
