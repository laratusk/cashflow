<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Unique;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

#[Unique]
final class EvidenceFee extends BalanceItem
{
    public function amount(): int
    {
        return 2000;
    }

    public function direction(): Direction
    {
        return Direction::Debit;
    }
}
