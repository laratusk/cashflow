<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class BatchAlreadySavedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This batch has already been saved.');
    }
}
