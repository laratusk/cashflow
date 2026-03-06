<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Exceptions;

use RuntimeException;

final class MissingReferenceException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A reference model must be set before loading or saving balance transactions.');
    }
}
