<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class BalanceNotSetException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Balance instance has not been set on this item.');
    }
}
