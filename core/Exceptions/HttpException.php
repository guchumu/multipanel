<?php

declare(strict_types=1);

namespace Core\Exceptions;

use Exception;

class HttpException extends Exception
{
    public function __construct(
        string $message = '',
        private int $statusCode = 500,
        ?Exception $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
