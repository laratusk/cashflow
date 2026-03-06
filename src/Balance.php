<?php

declare(strict_types=1);

namespace Laratusk\Cashflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laratusk\Cashflow\Attributes\Requires;
use Laratusk\Cashflow\Attributes\Rule;
use Laratusk\Cashflow\Attributes\Unique;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Events\BatchSaved;
use Laratusk\Cashflow\Exceptions\BalanceItemNotFoundException;
use Laratusk\Cashflow\Exceptions\BatchAlreadySavedException;
use Laratusk\Cashflow\Exceptions\DuplicateBalanceItemException;
use Laratusk\Cashflow\Exceptions\EmptyBatchException;
use Laratusk\Cashflow\Exceptions\InvalidAmountException;
use Laratusk\Cashflow\Exceptions\MissingCurrencyException;
use Laratusk\Cashflow\Exceptions\MissingExchangeRateException;
use Laratusk\Cashflow\Exceptions\MissingReferenceException;
use Laratusk\Cashflow\Exceptions\MissingRequiredBalanceItemException;
use Laratusk\Cashflow\Models\BalanceTransaction;
use ReflectionClass;

final class Balance
{
    private ?string $currency = null;

    private ?Model $reference = null;

    private string $batchId;

    /** @var Collection<int, BalanceItem> */
    private Collection $items;

    private bool $committed = false;

    private bool $loaded = false;

    private function __construct(
        private readonly Model $balanceable,
    ) {
        $this->batchId = (string) Str::orderedUuid();
        $this->items = new Collection;
    }

    public static function for(Model $balanceable): self
    {
        return new self($balanceable);
    }

    public function reference(Model $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function insert(BalanceItem $item): self
    {
        if ($this->committed) {
            throw new BatchAlreadySavedException;
        }

        $ref = new ReflectionClass($item);

        $this->validateItem($item, $ref);

        if (! $this->loaded) {
            $this->get();
        }

        $item->setBalance($this);

        if ($this->hasAttribute($ref, Unique::class) && $this->has($item->key())) {
            throw new DuplicateBalanceItemException($item->key());
        }

        $this->checkRequirements($item, $ref);

        $this->items->push($item);

        return $this;
    }

    public function batchId(): string
    {
        return $this->batchId;
    }

    public function save(): void
    {
        if (! $this->reference) {
            throw new MissingReferenceException;
        }

        if ($this->committed) {
            throw new BatchAlreadySavedException;
        }

        $unsaved = $this->unsaved();

        if ($unsaved->isEmpty()) {
            throw new EmptyBatchException;
        }

        $baseModel = $this->model();

        DB::transaction(function () use ($unsaved, $baseModel): void {
            $unsaved->each(function (BalanceItem $item) use ($baseModel): void {
                $currency = $item->currency() ?? $this->currency;

                if ($currency === null) {
                    throw new MissingCurrencyException($item->key());
                }

                $exchangeRate = $item->exchangeRate();

                if ($currency !== $this->currency && $exchangeRate === 1.0) {
                    throw new MissingExchangeRateException($item->key());
                }

                $model = $baseModel->newInstance();
                $model->batch_id = $this->batchId;
                $model->balanceable()->associate($this->balanceable);
                $model->reference()->associate($this->reference);

                $amount = $item->amount();

                if ($amount < 0) {
                    throw new InvalidAmountException($item->key(), $amount);
                }

                $model->direction = $item->direction();
                $model->amount = $amount;
                $model->currency = $currency;
                $model->exchange_rate = $exchangeRate;
                $model->key = $item->key();
                $model->metadata = $item->metadata;
                $model->save();

                $item->saved = true;
            });
        });

        event(new BatchSaved(
            batchId: $this->batchId,
            balanceable: $this->balanceable,
            reference: $this->reference,
            items: $unsaved,
        ));

        $this->committed = true;
    }

    public function fresh(): self
    {
        $balance = new self($this->balanceable);

        if ($this->reference) {
            $balance->reference($this->reference);
        }

        if ($this->currency) {
            $balance->currency($this->currency);
        }

        return $balance;
    }

    public static function dropBatch(string $batchId): int
    {
        /** @var class-string<BalanceTransaction> $class */
        $class = config('cashflow.model', BalanceTransaction::class);

        /** @var int $count */
        $count = (new $class)->newQuery()->where('batch_id', $batchId)->delete();

        return $count;
    }

    /** @param class-string<BalanceItem> $balanceItemClass */
    public function amountOf(string $balanceItemClass): int
    {
        $item = $this->items->first(fn (BalanceItem $i): bool => $i->key() === $balanceItemClass);

        if (! $item) {
            throw new BalanceItemNotFoundException($balanceItemClass);
        }

        return $item->amount();
    }

    public function has(string $balanceItemClass): bool
    {
        return $this->items->contains(fn (BalanceItem $i): bool => $i->key() === $balanceItemClass);
    }

    /** @return Collection<int, BalanceItem> */
    public function items(): Collection
    {
        return $this->items;
    }

    /** @return Collection<int, BalanceItem> */
    public function unsaved(): Collection
    {
        return $this->items->filter(fn (BalanceItem $i): bool => ! $i->saved);
    }

    /** @return Collection<int, BalanceItem> */
    public function saved(): Collection
    {
        return $this->items->filter(fn (BalanceItem $i): bool => $i->saved);
    }

    public function get(): self
    {
        if (! $this->reference) {
            throw new MissingReferenceException;
        }

        if ($this->loaded) {
            return $this;
        }

        $records = $this->model()->newQuery()
            ->whereMorphedTo('balanceable', $this->balanceable)
            ->whereMorphedTo('reference', $this->reference)
            ->get();

        $records->each(function (BalanceTransaction $record): void {
            $item = BalanceItem::resolveFrom($record);
            $item->setBalance($this);
            $this->items->push($item);
        });

        $this->loaded = true;

        return $this;
    }

    private function model(): BalanceTransaction
    {
        /** @var class-string<BalanceTransaction> $class */
        $class = config('cashflow.model', BalanceTransaction::class);

        return new $class;
    }

    /**
     * @param  ReflectionClass<BalanceItem>  $ref
     */
    private function validateItem(BalanceItem $item, ReflectionClass $ref): void
    {
        $constructor = $ref->getConstructor();

        if (! $constructor) {
            return;
        }

        $rules = [];
        $data = [];

        foreach ($constructor->getParameters() as $param) {
            $paramRules = [];
            foreach ($param->getAttributes(Rule::class) as $attr) {
                array_push($paramRules, ...$attr->newInstance()->rules);
            }
            if ($paramRules !== []) {
                $name = $param->getName();
                $rules[$name] = $paramRules;
                $data[$name] = $ref->getProperty($name)->getValue($item);
            }
        }

        if ($rules !== []) {
            validator($data, $rules)->validate();
        }
    }

    /**
     * @param  ReflectionClass<BalanceItem>  $ref
     * @param  class-string  $attribute
     */
    private function hasAttribute(ReflectionClass $ref, string $attribute): bool
    {
        return $ref->getAttributes($attribute) !== [];
    }

    /**
     * @param  ReflectionClass<BalanceItem>  $ref
     */
    private function checkRequirements(BalanceItem $item, ReflectionClass $ref): void
    {
        foreach ($ref->getAttributes(Requires::class) as $attr) {
            $required = $attr->newInstance()->balanceItemClass;

            if (! $this->has($required)) {
                throw new MissingRequiredBalanceItemException($item->key(), $required);
            }
        }
    }
}
