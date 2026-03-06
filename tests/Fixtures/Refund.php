<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Requires;
use Laratusk\Cashflow\Attributes\Rule;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

#[Requires(Payment::class)]
final class Refund extends BalanceItem
{
    public function __construct(
        #[Rule('required', 'integer', 'min:1')]
        private readonly int $amount,
    ) {}

    public function direction(): Direction
    {
        return Direction::Debit;
    }
}
