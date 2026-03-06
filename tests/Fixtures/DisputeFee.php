<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Rule;
use Laratusk\Cashflow\Attributes\Unique;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

#[Unique]
final class DisputeFee extends BalanceItem
{
    public function __construct(
        #[Rule('required', 'integer', 'min:0')]
        private readonly int $amount = 2000,

        #[Rule('required')]
        private readonly Direction $direction = Direction::Debit,

        #[Rule('required', 'numeric', 'gt:0')]
        private readonly float $exchangeRate = 0.914,
    ) {}

    public function amount(): int
    {
        return $this->amount;
    }

    public function direction(): Direction
    {
        return $this->direction;
    }

    public function exchangeRate(): float
    {
        return $this->exchangeRate;
    }

    public function currency(): string
    {
        return 'EUR';
    }
}
