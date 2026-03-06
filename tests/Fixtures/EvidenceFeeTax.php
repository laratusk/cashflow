<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Requires;
use Laratusk\Cashflow\Attributes\Unique;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

#[Unique]
#[Requires(EvidenceFee::class)]
final class EvidenceFeeTax extends BalanceItem
{
    public function amount(): int
    {
        return 400;
    }

    public function direction(): Direction
    {
        return Direction::Debit;
    }
}
