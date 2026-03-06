<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class DuplicateBalanceItemException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Duplicate balance item: {$key}");
    }
}
