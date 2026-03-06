<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Rule;
use Laratusk\Cashflow\Attributes\Unique;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

#[Unique]
final class GatewayFeeTax extends BalanceItem
{
    public function __construct(
        #[Rule('required_without:taxRate', 'nullable', 'integer', 'min:0')]
        private readonly ?int $amount = null,

        #[Rule('required_without:amount', 'nullable', 'numeric', 'gte:0', 'max:100')]
        private readonly ?float $taxRate = null,
    ) {}

    public function amount(): int
    {
        if ($this->amount !== null) {
            return $this->amount;
        }

        return (int) ceil($this->balance()->amountOf(GatewayFee::class) * $this->taxRate / 100);
    }

    public function direction(): Direction
    {
        return Direction::Debit;
    }
}
