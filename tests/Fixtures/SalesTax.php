<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Rule;
use Laratusk\Cashflow\Attributes\Unique;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

#[Unique]
final class SalesTax extends BalanceItem
{
    public function __construct(
        #[Rule('required', 'numeric', 'gt:0', 'max:100')]
        private readonly float $taxRate,
    ) {}

    public function amount(): int
    {
        return (int) ceil($this->balance()->amountOf(Payment::class) * $this->taxRate / 100);
    }

    public function direction(): Direction
    {
        return Direction::Debit;
    }
}
