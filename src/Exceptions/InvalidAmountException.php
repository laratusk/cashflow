<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class InvalidAmountException extends RuntimeException
{
    public function __construct(string $key, int $amount)
    {
        parent::__construct("Balance item '{$key}' resolved a negative amount: {$amount}.");
    }
}
