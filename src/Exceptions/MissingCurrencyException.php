<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class MissingCurrencyException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("No currency resolved for balance item '{$key}'. Set currency on Balance or override currency() on the item.");
    }
}
