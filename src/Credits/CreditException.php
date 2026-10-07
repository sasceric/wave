<?php

declare(strict_types=1);

namespace App\Credits;

final class CreditException extends \RuntimeException
{
    public function __construct(public readonly string $key, public readonly int $status = 400)
    {
        parent::__construct($key);
    }
}
