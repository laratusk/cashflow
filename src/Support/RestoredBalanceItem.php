<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Support;

use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;

/** @internal */
final class RestoredBalanceItem extends BalanceItem
{
    /**
     * @param  array<mixed>|null  $metadata
     */
    public function __construct(
        private readonly string $storedKey,
        private readonly int $storedAmount,
        private readonly Direction $storedDirection,
        private readonly string $storedCurrency,
        private readonly float $storedExchangeRate,
        ?array $metadata = null,
    ) {
        $this->saved = true;
        $this->metadata = $metadata;
    }

    public function key(): string
    {
        return $this->storedKey;
    }

    public function amount(): int
    {
        return $this->storedAmount;
    }

    public function direction(): Direction
    {
        return $this->storedDirection;
    }

    public function currency(): string
    {
        return $this->storedCurrency;
    }

    public function exchangeRate(): float
    {
        return $this->storedExchangeRate;
    }
}
