# Cashflow

A double-entry balance transaction ledger for Laravel. Track credits, debits, fees, and derived amounts with type-safe
balance items, validation, and dependency enforcement.

## Installation

```bash
composer require laratusk/cashflow
```

Publish the migration and run it:

```bash
php artisan vendor:publish --tag=cashflow-migrations
php artisan migrate
```

Optionally publish the config (only needed if you want to override the `BalanceTransaction` model):

```bash
php artisan vendor:publish --tag=cashflow-config
```

## Quick Start

```php
use Laratusk\Cashflow\Balance;
$balance = Balance::for($account)
    ->reference($order)
    ->currency('USD');

$balance->insert(new Payment(amount: 10000));
$balance->insert(new GatewayFee(amount: 290));
$balance->insert(new GatewayFeeTax(amount: 52));
$balance->insert(new SalesTax(taxRate: 8.0));

$balance->save();

$balance->batchId();                  // "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d"
$balance->amountOf(Payment::class);   // 10000
$balance->amountOf(GatewayFee::class); // 290
```

## Setup

Add `HasBalanceTransactions` to any model that holds a balance:

```php
use Laratusk\Cashflow\Concerns\HasBalanceTransactions;

class Account extends Model
{
    use HasBalanceTransactions;
}
```

This adds a `balanceTransactions()` morph-many relationship.

## Why Balance Items Are Not Shipped

Cashflow does not ship any `BalanceItem` classes. Every business has different transaction types -- a SaaS platform has
subscriptions and usage fees, a marketplace has commissions and payouts, a payment processor has gateway fees and
dispute charges.

Balance items are **your domain logic**. The package provides the base class (`BalanceItem`), attributes (`#[Unique]`,
`#[Rule]`, `#[Requires]`), and the `Balance` engine. You define the items that match your business.

## Generating Balance Items

Use the artisan command to scaffold new items interactively:

```bash
php artisan make:balance-item
```

The command asks:

1. **Class name** -- e.g. `Payment`, `GatewayFee`, `Refund`
2. **Direction** -- Debit or Credit
3. **Amount source** -- Passed from outside, fixed value, or derived from another item
4. **Unique?** -- Whether to add `#[Unique]`
5. **Requires?** -- Dependency on another balance item

Files are created in `app/Cashflow/BalanceItems/`. Add validation rules directly in the generated class.

## Defining Balance Items

Extend `BalanceItem` and define `direction()`. If the constructor has an `$amount` parameter, it is resolved
automatically -- no need to override `amount()`:

```php
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;
use Laratusk\Cashflow\Attributes\Rule;

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
```

The base class auto-resolves `$amount` and `$direction` from constructor properties. Override `amount()` only when you
need custom logic (derived amounts, fixed values).

### Fixed vs Derived Amounts

Items can receive their amount directly or calculate it from siblings:

```php
// Fixed amount (e.g. Stripe returns the fee value)
$balance->insert(new GatewayFee(amount: 290));

// Derived amount (e.g. TrustPayment charges a percentage)
$balance->insert(new GatewayFee(percentage: 2.9));
```

Implementation with `required_without` validation:

```php
#[Unique]
final class GatewayFee extends BalanceItem
{
    public function __construct(
        #[Rule('required_without:percentage', 'nullable', 'integer', 'min:1')]
        private readonly ?int $amount = null,

        #[Rule('required_without:amount', 'nullable', 'numeric', 'gt:0', 'max:100')]
        private readonly ?float $percentage = null,
    ) {}

    public function amount(): int
    {
        if ($this->amount !== null) {
            return $this->amount;
        }

        return (int) ceil(
            $this->balance()->amountOf(Payment::class) * $this->percentage / 100
        );
    }

    public function direction(): Direction
    {
        return Direction::Debit;
    }
}
```

### Override Protection

- **Lock a value** -- hardcode it in the method, not the constructor.
- **Allow override** -- accept it as a constructor parameter.

```php
// Hardcoded: always 2000, not overridable
final class EvidenceFee extends BalanceItem
{
    public function amount(): int { return 2000; }
    public function direction(): Direction { return Direction::Debit; }
}

// Overridable: amount comes from outside (auto-resolved from constructor)
final class Payment extends BalanceItem
{
    public function __construct(private readonly int $amount) {}
    public function direction(): Direction { return Direction::Credit; }
}
```

### Multi-Currency Items

Override `currency()` and provide an exchange rate:

```php
#[Unique]
final class DisputeFee extends BalanceItem
{
    public function __construct(
        private readonly float $exchangeRate,
        private readonly Direction $direction = Direction::Debit,
    ) {}

    public function amount(): int { return 2000; }
    public function direction(): Direction { return $this->direction; }
    public function exchangeRate(): float { return $this->exchangeRate; }
    public function currency(): ?string { return 'EUR'; }
}
```

When an item's currency differs from the balance currency, an exchange rate other than `1.0` is required -- otherwise
`save()` throws `MissingExchangeRateException`.

## Attributes

### `#[Rule(...)]`

Laravel validation rules for constructor parameters. Validated on `insert()`. Supports all Laravel rules including
cross-field rules like `required_without`.

```php
public function __construct(
    #[Rule('required', 'integer', 'min:1')]
    private readonly int $amount,
) {}
```

### `#[Unique]`

One instance per reference morph. Inserting a duplicate throws `DuplicateBalanceItemException`. Checked against
in-memory and DB items.

```php
#[Unique]
final class GatewayFee extends BalanceItem { ... }
```

### `#[Requires(ClassName::class)]`

Enforces dependency on another balance item. Repeatable. Checked against in-memory and DB items.

```php
#[Requires(EvidenceFee::class)]
final class EvidenceFeeReversal extends BalanceItem { ... }
```

## Metadata

Attach free-form data to any balance item. Stored as JSON, restored when loading from DB.

```php
// Public property
$payment = new Payment(amount: 5000);
$payment->metadata = ['stripe_charge_id' => 'ch_123', 'source' => 'api'];

// Fluent setter
$balance->insert(
    (new Payment(amount: 5000))->withMetadata(['stripe_charge_id' => 'ch_123'])
);
```

Reading metadata back:

```php
$balance = Balance::for($account)->reference($order)->get();
$balance->items()->first()->metadata; // ['stripe_charge_id' => 'ch_123', ...]
```

## Batch Operations

Every `save()` groups items under a single `batch_id` (UUID).

```php
$balance->save();
$batchId = $balance->batchId();

// Delete an entire batch
$deleted = Balance::dropBatch($batchId); // returns number of deleted rows
```

## Reading from DB

```php
$balance = Balance::for($account)->reference($order)->get();

$balance->items();                    // Collection<BalanceItem>
$balance->has(Payment::class);        // true
$balance->amountOf(Payment::class);   // 10000
```

Items loaded from DB are read-only `BalanceItem` instances with `saved = true`.

## Uniqueness Scoping

Uniqueness is scoped per **reference morph**:

```php
// Different references -- OK
Balance::for($account)->reference($order1)->insert(new GatewayFee(amount: 290));
Balance::for($account)->reference($order2)->insert(new GatewayFee(amount: 145));

// Same reference -- DuplicateBalanceItemException
Balance::for($account)->reference($order1)->insert(new GatewayFee(amount: 290));
Balance::for($account)->reference($order1)->insert(new GatewayFee(amount: 145)); // throws
```

## Real-World Examples

### Stripe Payment

```php
// Stripe provides exact fee amounts via API
$balance = Balance::for($account)->reference($order)->currency('USD');

$balance->insert((new Payment(amount: 10000))->withMetadata(['charge_id' => 'ch_xxx']));
$balance->insert(new GatewayFee(amount: 290));
$balance->insert(new GatewayFeeTax(amount: 52));
$balance->insert(new SalesTax(taxRate: 8.0));

$balance->save();
```

### TrustPayment (Rate-Based)

```php
// TrustPayment charges percentage-based fees
$balance = Balance::for($account)->reference($order)->currency('USD');

$balance->insert(new Payment(amount: 10000));
$balance->insert(new GatewayFee(percentage: 2.9));      // ceil(10000 * 2.9%) = 290
$balance->insert(new GatewayFeeTax(taxRate: 20.0));      // ceil(290 * 20%) = 58

$balance->save();
```

### Refund

```php
$balance = Balance::for($account)->reference($order)->currency('USD');

$balance->insert(new Refund(amount: 5000)); // requires Payment to exist in balance

$balance->save();
```

### Dispute with Evidence Fee

```php
// Evidence submission
$b1 = Balance::for($account)->reference($order)->currency('USD');
$b1->insert(new EvidenceFee);     // always 2000
$b1->insert(new EvidenceFeeTax);  // always 400, requires EvidenceFee
$b1->save();

// Dispute won -- reverse the evidence fee
$b2 = Balance::for($account)->reference($order)->currency('USD');
$b2->insert(new EvidenceFeeReversal); // requires EvidenceFee in DB
$b2->save();
```

## API Reference

### `Balance`

| Method                             | Description                               |
|------------------------------------|-------------------------------------------|
| `Balance::for(Model $balanceable)` | Create a new balance instance             |
| `->reference(Model $ref)`          | Scope to a reference morph                |
| `->currency(string $currency)`     | Set the balance currency (ISO 4217)       |
| `->insert(BalanceItem $item)`      | Add an item to the batch                  |
| `->save()`                         | Persist unsaved items in a DB transaction |
| `->batchId()`                      | Get the batch UUID                        |
| `->get()`                          | Load saved items from DB                  |
| `->items()`                        | All items (saved + unsaved)               |
| `->saved()`                        | Only persisted items                      |
| `->unsaved()`                      | Only pending items                        |
| `->has(string $class)`             | Check if an item key exists               |
| `->amountOf(string $class)`        | Get amount of a specific item             |
| `Balance::dropBatch(string $id)`   | Delete all transactions in a batch        |

### `BalanceItem`

| Method                       | Default                                  | Override                           |
|------------------------------|------------------------------------------|------------------------------------|
| `key()`                      | FQCN                                     | Optional                           |
| `amount()`                   | Auto-resolved from `$amount` property    | Override for derived/fixed amounts |
| `direction()`                | Auto-resolved from `$direction` property | Override to hardcode direction     |
| `currency()`                 | `null` (uses balance currency)           | Optional                           |
| `exchangeRate()`             | `1.0`                                    | Optional                           |
| `withMetadata(?array $data)` | Fluent setter                            | --                                 |

### Exceptions

| Exception                             | When                                 |
|---------------------------------------|--------------------------------------|
| `MissingCurrencyException`            | No currency on balance or item       |
| `MissingExchangeRateException`        | Different currency but rate is 1.0   |
| `DuplicateBalanceItemException`       | `#[Unique]` item already exists      |
| `MissingRequiredBalanceItemException` | `#[Requires]` dependency missing     |
| `BatchAlreadySavedException`          | `save()` or `insert()` after commit  |
| `EmptyBatchException`                 | `save()` with no unsaved items       |
| `BalanceItemNotFoundException`        | `amountOf()` for missing key         |
| `BalanceNotSetException`              | `balance()` called before `insert()` |

## Currency

Cashflow uses plain ISO 4217 strings (`'USD'`, `'EUR'`, `'TRY'`) for currency codes. No currency enum is shipped -- use
whichever currency library fits your project.

For validation, [squirephp/currencies-en](https://github.com/squirephp/currencies-en) works well:

```bash
composer require squirephp/currencies-en
```

```php
use Squire\Models\Currency;

// Look up currency data
Currency::find('USD')->name;   // "US Dollar"
Currency::find('USD')->symbol; // "$"
```

## Database Schema

| Column           | Type            | Notes                 |
|------------------|-----------------|-----------------------|
| id               | bigint (PK)     | auto-increment        |
| batch_id         | uuid            | indexed, groups items |
| balanceable_id   | bigint          | morph FK              |
| balanceable_type | string          | morph type            |
| reference_id     | bigint/null     | morph FK              |
| reference_type   | string/null     | morph type            |
| direction        | string          | `debit` / `credit`    |
| amount           | bigint unsigned | minor units (cents)   |
| currency         | string(3)       | ISO 4217              |
| exchange_rate    | decimal(12,6)   | default 1.0           |
| key              | string          | FQCN by default       |
| metadata         | json/null       | free-form data        |
| created_at       | timestamp       | auto-set              |

## Configuration

```php
// config/cashflow.php
return [
    'model' => \Laratusk\Cashflow\Models\BalanceTransaction::class,
];
```

Override the model to customize the table name, casts, or add behavior.

## License

MIT
