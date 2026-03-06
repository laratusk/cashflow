<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class MissingExchangeRateException extends RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct("Balance item '{$key}' has a different currency but exchange rate is 1.0.");
    }
}
