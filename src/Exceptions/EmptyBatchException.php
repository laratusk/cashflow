<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class EmptyBatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot save an empty batch.');
    }
}
