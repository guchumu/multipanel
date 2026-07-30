<?php

declare(strict_types=1);

namespace Core\Exceptions;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Recurso no encontrado.')
    {
        parent::__construct($message, 404);
    }
}
