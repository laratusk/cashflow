<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laratusk\Cashflow\Models\BalanceTransaction;

trait HasBalanceTransactions
{
    public function balanceTransactions(): MorphMany
    {
        /** @var class-string<BalanceTransaction> $model */
        $model = config('cashflow.model', BalanceTransaction::class);

        return $this->morphMany($model, 'balanceable');
    }
}
