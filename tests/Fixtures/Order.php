<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laratusk\Cashflow\Concerns\HasBalanceTransactions;

class Order extends Model
{
    use HasBalanceTransactions;

    protected $table = 'orders';

    protected $guarded = [];
}
