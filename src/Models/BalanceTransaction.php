<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Laratusk\Cashflow\Enums\Direction;

/**
 * @property int $id
 * @property string $batch_id
 * @property int $balanceable_id
 * @property string $balanceable_type
 * @property int|null $reference_id
 * @property string|null $reference_type
 * @property Direction $direction
 * @property int $amount
 * @property string $currency
 * @property float $exchange_rate
 * @property string $key
 * @property array<mixed>|null $metadata
 * @property Carbon $created_at
 */
class BalanceTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'balance_transactions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'amount' => 'integer',
            'metadata' => 'array',
            'exchange_rate' => 'float',
            'created_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function balanceable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
