<?php

declare(strict_types=1);
use Laratusk\Cashflow\Models\BalanceTransaction;

return [
    'model' => BalanceTransaction::class,
    'balance_items_namespace' => 'App\\Ledgers',
];
