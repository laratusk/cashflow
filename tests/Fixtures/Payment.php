<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Laratusk\Cashflow\Attributes\Rule;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

final class Payment extends BalanceItem
{
    public function __construct(
        #[Rule('required', 'integer', 'min:1')]
        private readonly int $amount,
    ) {}

    public function direction(): Direction
    {
        return Direction::Credit;
    }
}
