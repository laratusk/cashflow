<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Contracts;

use Laratusk\Cashflow\Balance;
use Laratusk\Cashflow\Enums\Direction;
use Laratusk\Cashflow\Exceptions\BalanceNotSetException;
use Laratusk\Cashflow\Models\BalanceTransaction;
use Laratusk\Cashflow\Support\RestoredBalanceItem;
use LogicException;
use ReflectionClass;

abstract class BalanceItem
{
    public bool $saved = false;

    /** @var array<mixed>|null */
    public ?array $metadata = null;

    private ?Balance $balance = null;

    public function key(): string
    {
        return static::class;
    }

    public function amount(): int
    {
        /** @var int|null $value */
        $value = $this->resolveProperty('amount');

        return $value
            ?? throw new LogicException('Override amount() or add a constructor $amount parameter in '.static::class);
    }

    public function direction(): Direction
    {
        /** @var Direction|null $value */
        $value = $this->resolveProperty('direction');

        return $value
            ?? throw new LogicException('Override direction() or add a constructor $direction parameter in '.static::class);
    }

    public function currency(): ?string
    {
        return null;
    }

    public function exchangeRate(): float
    {
        return 1.0;
    }

    /** @internal */
    public function setBalance(Balance $balance): void
    {
        $this->balance = $balance;
    }

    protected function balance(): Balance
    {
        return $this->balance ?? throw new BalanceNotSetException;
    }

    /** @param array<mixed>|null $metadata */
    public function withMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /** @internal */
    public static function resolveFrom(BalanceTransaction $record): self
    {
        return new RestoredBalanceItem(
            storedKey: $record->key,
            storedAmount: $record->amount,
            storedDirection: $record->direction,
            storedCurrency: $record->currency,
            storedExchangeRate: $record->exchange_rate,
            metadata: $record->metadata,
        );
    }

    private function resolveProperty(string $name): mixed
    {
        $ref = new ReflectionClass($this);

        if (! $ref->hasProperty($name)) {
            return null;
        }

        $prop = $ref->getProperty($name);

        if ($prop->getDeclaringClass()->getName() === self::class) {
            return null;
        }

        return $prop->getValue($this);
    }
}
