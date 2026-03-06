<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laratusk\Cashflow\Contracts\BalanceItem;

class BatchSaved
{
    /**
     * @param  Collection<int, BalanceItem>  $items
     */
    public function __construct(
        public readonly string $batchId,
        public readonly Model $balanceable,
        public readonly ?Model $reference,
        public readonly Collection $items,
    ) {}
}
