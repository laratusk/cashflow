<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class MissingRequiredBalanceItemException extends RuntimeException
{
    public function __construct(string $itemKey, string $requiredKey)
    {
        parent::__construct("Balance item '{$itemKey}' requires '{$requiredKey}' to be present.");
    }
}
