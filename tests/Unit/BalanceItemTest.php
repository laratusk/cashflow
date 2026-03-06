<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Unit;

use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;
use Laratusk\Cashflow\Exceptions\BalanceNotSetException;
use Laratusk\Cashflow\Models\BalanceTransaction;
use Laratusk\Cashflow\Support\RestoredBalanceItem;
use LogicException;
use PHPUnit\Framework\TestCase;

class BalanceItemTest extends TestCase
{
    public function test_resolve_from_creates_read_only_item(): void
    {
        $record = new BalanceTransaction;
        $record->key = 'App\\Items\\Payment';
        $record->amount = 5000;
        $record->direction = Direction::Credit;
        $record->currency = 'USD';
        $record->exchange_rate = 1.0;

        $item = BalanceItem::resolveFrom($record);

        $this->assertInstanceOf(RestoredBalanceItem::class, $item);
        $this->assertTrue($item->saved);
        $this->assertEquals('App\\Items\\Payment', $item->key());
        $this->assertEquals(5000, $item->amount());
        $this->assertEquals(Direction::Credit, $item->direction());
        $this->assertEquals('USD', $item->currency());
        $this->assertEquals(1.0, $item->exchangeRate());
    }

    public function test_key_defaults_to_fqcn(): void
    {
        $item = new class extends BalanceItem {};
        $this->assertStringContainsString('@anonymous', $item->key());
    }

    public function test_balance_throws_when_not_set(): void
    {
        $item = new class extends BalanceItem
        {
            public function callBalance()
            {
                return $this->balance();
            }

            public function direction(): Direction
            {
                return Direction::Credit;
            }
        };

        $this->expectException(BalanceNotSetException::class);
        $item->callBalance();
    }

    public function test_exchange_rate_defaults_to_one(): void
    {
        $item = new class extends BalanceItem {};
        $this->assertEquals(1.0, $item->exchangeRate());
    }

    public function test_currency_defaults_to_null(): void
    {
        $item = new class extends BalanceItem {};
        $this->assertNull($item->currency());
    }

    public function test_amount_throws_when_not_overridden(): void
    {
        $item = new class extends BalanceItem {};

        $this->expectException(LogicException::class);
        $item->amount();
    }

    public function test_direction_throws_when_not_overridden(): void
    {
        $item = new class extends BalanceItem {};

        $this->expectException(LogicException::class);
        $item->direction();
    }

    public function test_with_metadata_returns_self(): void
    {
        $item = new class extends BalanceItem {};
        $result = $item->withMetadata(['key' => 'value']);

        $this->assertSame($item, $result);
        $this->assertEquals(['key' => 'value'], $item->metadata);
    }

    public function test_amount_from_constructor_property(): void
    {
        $item = new class(500) extends BalanceItem
        {
            public function __construct(
                private readonly int $amount,
            ) {}

            public function direction(): Direction
            {
                return Direction::Credit;
            }
        };

        $this->assertEquals(500, $item->amount());
    }

    public function test_direction_from_constructor_property(): void
    {
        $item = new class(Direction::Debit) extends BalanceItem
        {
            public function __construct(
                private readonly Direction $direction,
            ) {}

            public function amount(): int
            {
                return 100;
            }
        };

        $this->assertEquals(Direction::Debit, $item->direction());
    }
}
