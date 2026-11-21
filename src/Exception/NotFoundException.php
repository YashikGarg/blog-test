<?php

declare(strict_types=1);

namespace App\Exception;

final class NotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message);
    }
}
