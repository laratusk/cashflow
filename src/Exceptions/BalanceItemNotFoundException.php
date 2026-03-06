<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class BalanceItemNotFoundException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Balance item not found: {$key}");
    }
}
