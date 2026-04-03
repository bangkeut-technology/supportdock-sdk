<?php

declare(strict_types=1);

namespace SupportDock\Exception;

class ValidationException extends SupportDockException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 400, $previous);
    }
}
