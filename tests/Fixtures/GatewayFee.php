<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Rule;
use Laratusk\Cashflow\Attributes\Unique;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

#[Unique]
final class GatewayFee extends BalanceItem
{
    public function __construct(
        #[Rule('required_without:percentage', 'nullable', 'integer', 'min:1')]
        private readonly ?int $amount = null,

        #[Rule('required_without:amount', 'nullable', 'numeric', 'gt:0', 'max:100')]
        private readonly ?float $percentage = null,
    ) {}

    public function amount(): int
    {
        if ($this->amount !== null) {
            return $this->amount;
        }

        return (int) ceil($this->balance()->amountOf(Payment::class) * $this->percentage / 100);
    }

    public function direction(): Direction
    {
        return Direction::Debit;
    }
}
