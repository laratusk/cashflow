<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Attributes;

use Attribute;
use Laratusk\Cashflow\Contracts\BalanceItem;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Requires
{
    /**
     * @param  class-string<BalanceItem>  $balanceItemClass
     */
    public function __construct(
        public string $balanceItemClass,
    ) {}
}
