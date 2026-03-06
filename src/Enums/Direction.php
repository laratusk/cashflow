<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Enums;

enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
