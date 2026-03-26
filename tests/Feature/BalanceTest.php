<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laratusk\Cashflow\Balance;
use Laratusk\Cashflow\Contracts\BalanceItem;
use Laratusk\Cashflow\Enums\Direction;
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
use Laratusk\Cashflow\Tests\Fixtures\Account;
use Laratusk\Cashflow\Tests\Fixtures\DisputeFee;
use Laratusk\Cashflow\Tests\Fixtures\EvidenceFee;
use Laratusk\Cashflow\Tests\Fixtures\EvidenceFeeReversal;
use Laratusk\Cashflow\Tests\Fixtures\EvidenceFeeTax;
use Laratusk\Cashflow\Tests\Fixtures\GatewayFee;
use Laratusk\Cashflow\Tests\Fixtures\GatewayFeeTax;
use Laratusk\Cashflow\Tests\Fixtures\Order;
use Laratusk\Cashflow\Tests\Fixtures\Payment;
use Laratusk\Cashflow\Tests\Fixtures\Refund;
use Laratusk\Cashflow\Tests\Fixtures\SalesTax;
use Laratusk\Cashflow\Tests\TestCase;

class BalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->timestamps();
        });
    }

    private function createAccount(): Account
    {
        return Account::create(['name' => 'Test Account']);
    }

    private function createOrder(Account $account): Order
    {
        return Order::create(['account_id' => $account->id]);
    }

    // -------------------------------------------------------
    // Stripe scenario: gateway fee & tax amounts given directly
    // -------------------------------------------------------

    public function test_stripe_payment_with_fixed_gateway_fees(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        // Stripe: payment 10000 cents, gateway fee 290 cents, gateway fee tax 52 cents — all from Stripe API
        $balance->insert(new Payment(amount: 10000));
        $balance->insert(new GatewayFee(amount: 290));
        $balance->insert(new GatewayFeeTax(amount: 52));
        $balance->insert(new SalesTax(taxRate: 8.0));
        $balance->save();

        $this->assertCount(4, $balance->items());
        $this->assertEquals(10000, $balance->amountOf(Payment::class));
        $this->assertEquals(290, $balance->amountOf(GatewayFee::class));
        $this->assertEquals(52, $balance->amountOf(GatewayFeeTax::class));
        // SalesTax: ceil(10000 * 8.0 / 100) = 800
        $this->assertEquals(800, $balance->amountOf(SalesTax::class));

        $this->assertDatabaseCount('balance_transactions', 4);

        $this->assertDatabaseHas('balance_transactions', [
            'balanceable_type' => Account::class,
            'balanceable_id' => $account->id,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'direction' => 'credit',
            'amount' => 10000,
            'key' => Payment::class,
        ]);

        $this->assertDatabaseHas('balance_transactions', [
            'direction' => 'debit',
            'amount' => 290,
            'key' => GatewayFee::class,
        ]);

        $this->assertDatabaseHas('balance_transactions', [
            'direction' => 'debit',
            'amount' => 52,
            'key' => GatewayFeeTax::class,
        ]);
    }

    // -------------------------------------------------------
    // TrustPayment scenario: gateway fee & tax rate-based
    // -------------------------------------------------------

    public function test_trustpayment_with_rate_based_gateway_fees(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        // TrustPayment: fee is 2.9% of payment, tax is 20% of fee
        $balance->insert(new Payment(amount: 10000));
        $balance->insert(new GatewayFee(percentage: 2.9));
        $balance->insert(new GatewayFeeTax(taxRate: 20.0));
        $balance->insert(new SalesTax(taxRate: 18.0));
        $balance->save();

        $this->assertCount(4, $balance->items());
        $this->assertEquals(10000, $balance->amountOf(Payment::class));
        // GatewayFee: ceil(10000 * 2.9 / 100) = 290
        $this->assertEquals(290, $balance->amountOf(GatewayFee::class));
        // GatewayFeeTax: ceil(290 * 20.0 / 100) = 58
        $this->assertEquals(58, $balance->amountOf(GatewayFeeTax::class));
        // SalesTax: ceil(10000 * 18.0 / 100) = 1800
        $this->assertEquals(1800, $balance->amountOf(SalesTax::class));

        $this->assertDatabaseCount('balance_transactions', 4);
    }

    // -------------------------------------------------------
    // Refund scenario
    // -------------------------------------------------------

    public function test_refund_after_payment(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        // First: payment batch
        $b1 = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $b1->insert(new Payment(amount: 10000));
        $b1->insert(new GatewayFee(amount: 290));
        $b1->insert(new GatewayFeeTax(amount: 52));
        $b1->save();

        // Second: refund batch (partial refund)
        $b2 = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $b2->insert(new Refund(amount: 5000));
        $b2->save();

        $this->assertEquals(5000, $b2->amountOf(Refund::class));

        $this->assertDatabaseHas('balance_transactions', [
            'direction' => 'debit',
            'amount' => 5000,
            'key' => Refund::class,
        ]);

        $this->assertDatabaseCount('balance_transactions', 4);
    }

    public function test_refund_requires_payment(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $this->expectException(MissingRequiredBalanceItemException::class);
        $balance->insert(new Refund(amount: 5000));
    }

    public function test_full_refund(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $b1 = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $b1->insert(new Payment(amount: 10000));
        $b1->insert(new GatewayFee(amount: 290));
        $b1->save();

        // Full refund
        $b2 = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $b2->insert(new Refund(amount: 10000));
        $b2->save();

        $this->assertEquals(10000, $b2->amountOf(Refund::class));
        $this->assertDatabaseCount('balance_transactions', 3);
    }

    // -------------------------------------------------------
    // Evidence fee scenario (always 2000 fee + 400 tax)
    // -------------------------------------------------------

    public function test_evidence_fee_with_tax(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert(new Payment(amount: 10000));
        $balance->insert(new EvidenceFee);
        $balance->insert(new EvidenceFeeTax);
        $balance->save();

        $this->assertEquals(2000, $balance->amountOf(EvidenceFee::class));
        $this->assertEquals(400, $balance->amountOf(EvidenceFeeTax::class));

        $this->assertDatabaseCount('balance_transactions', 3);
    }

    public function test_evidence_fee_tax_requires_evidence_fee(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 10000));

        $this->expectException(MissingRequiredBalanceItemException::class);
        $balance->insert(new EvidenceFeeTax);
    }

    public function test_evidence_fee_reversal_after_dispute_won(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        // First batch: payment + evidence fee + tax
        $b1 = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $b1->insert(new Payment(amount: 10000));
        $b1->insert(new EvidenceFee);
        $b1->insert(new EvidenceFeeTax);
        $b1->save();

        // Second batch: evidence fee reversal (dispute won)
        $b2 = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $b2->insert(new EvidenceFeeReversal);
        $b2->save();

        $this->assertEquals(2000, $b2->amountOf(EvidenceFeeReversal::class));
        $this->assertDatabaseCount('balance_transactions', 4);
    }

    // -------------------------------------------------------
    // Dispute fee with exchange rate
    // -------------------------------------------------------

    public function test_dispute_fee_with_different_currency(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new DisputeFee(exchangeRate: 0.914));
        $balance->save();

        $record = BalanceTransaction::first();
        $this->assertEquals(0.914, $record->exchange_rate);
        $this->assertEquals('EUR', $record->currency);
        $this->assertEquals(2000, $record->amount);
    }

    public function test_different_currency_requires_exchange_rate(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new DisputeFee(exchangeRate: 1.0));

        $this->expectException(MissingExchangeRateException::class);
        $balance->save();
    }

    // -------------------------------------------------------
    // Core behavior tests
    // -------------------------------------------------------

    public function test_insert_and_save_single_item(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert(new Payment(amount: 10000));
        $balance->save();

        $this->assertCount(1, $balance->items());
        $this->assertCount(1, $balance->saved());
        $this->assertCount(0, $balance->unsaved());

        $this->assertDatabaseHas('balance_transactions', [
            'balanceable_type' => Account::class,
            'balanceable_id' => $account->id,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'direction' => 'credit',
            'amount' => 10000,
            'currency' => 'USD',
            'key' => Payment::class,
        ]);
    }

    public function test_read_saved_items_from_database(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert(new Payment(amount: 5000));
        $balance->insert(new GatewayFee(amount: 145));
        $balance->save();

        $loaded = Balance::for($account)->reference($order)->get();

        $this->assertCount(2, $loaded->items());
        $this->assertCount(2, $loaded->saved());
        $this->assertTrue($loaded->unsaved()->isEmpty());
        $this->assertTrue($loaded->has(Payment::class));
        $this->assertTrue($loaded->has(GatewayFee::class));
        $this->assertEquals(5000, $loaded->amountOf(Payment::class));
    }

    public function test_cannot_save_batch_twice(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 1000));
        $balance->save();

        $this->expectException(BatchAlreadySavedException::class);
        $balance->save();
    }

    public function test_cannot_insert_after_save(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 1000));
        $balance->save();

        $this->expectException(BatchAlreadySavedException::class);
        $balance->insert(new Payment(amount: 2000));
    }

    public function test_cannot_save_empty_batch(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');

        $this->expectException(EmptyBatchException::class);
        $balance->save();
    }

    public function test_unique_item_cannot_be_duplicated_in_same_batch(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert(new Payment(amount: 10000));
        $balance->insert(new GatewayFee(amount: 290));

        $this->expectException(DuplicateBalanceItemException::class);
        $balance->insert(new GatewayFee(amount: 290));
    }

    public function test_unique_item_checks_against_saved_records(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $b1 = Balance::for($account)
            ->reference($order)
            ->currency('USD');
        $b1->insert(new Payment(amount: 10000));
        $b1->insert(new GatewayFee(amount: 290));
        $b1->save();

        $b2 = Balance::for($account)
            ->reference($order)
            ->currency('USD');
        $b2->insert(new Payment(amount: 5000));

        $this->expectException(DuplicateBalanceItemException::class);
        $b2->insert(new GatewayFee(amount: 145));
    }

    public function test_unique_items_allowed_for_different_references(): void
    {
        $account = $this->createAccount();
        $order1 = $this->createOrder($account);
        $order2 = $this->createOrder($account);

        $b1 = Balance::for($account)
            ->reference($order1)
            ->currency('USD');
        $b1->insert(new Payment(amount: 10000));
        $b1->insert(new GatewayFee(amount: 290));
        $b1->save();

        $b2 = Balance::for($account)
            ->reference($order2)
            ->currency('USD');
        $b2->insert(new Payment(amount: 5000));
        $b2->insert(new GatewayFee(amount: 145));
        $b2->save();

        $this->assertDatabaseCount('balance_transactions', 4);
    }

    public function test_validation_rejects_invalid_amount(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');

        $this->expectException(ValidationException::class);
        $balance->insert(new Payment(amount: 0));
    }

    public function test_gateway_fee_requires_amount_or_percentage(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 10000));

        $this->expectException(ValidationException::class);
        $balance->insert(new GatewayFee);
    }

    public function test_has_method(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 5000));

        $this->assertTrue($balance->has(Payment::class));
        $this->assertFalse($balance->has(GatewayFee::class));
    }

    public function test_batch_id_groups_items(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 10000));
        $balance->insert(new GatewayFee(amount: 290));
        $balance->save();

        $batchIds = BalanceTransaction::pluck('batch_id')->unique();
        $this->assertCount(1, $batchIds);
    }

    public function test_transaction_rolls_back_on_failure(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 10000));
        $balance->insert(new DisputeFee(exchangeRate: 1.0));

        try {
            $balance->save();
        } catch (MissingExchangeRateException) {
            // expected
        }

        $this->assertDatabaseCount('balance_transactions', 0);
    }

    public function test_has_balance_transactions_trait(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');
        $balance->insert(new Payment(amount: 5000));
        $balance->save();

        $this->assertCount(1, $account->balanceTransactions);
        $this->assertInstanceOf(BalanceTransaction::class, $account->balanceTransactions->first());
    }

    public function test_requires_attribute_enforces_dependency(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert(new Payment(amount: 10000));

        $this->expectException(MissingRequiredBalanceItemException::class);
        $balance->insert(new EvidenceFeeReversal);
    }

    public function test_requires_checks_against_saved_items_from_db(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $b1 = Balance::for($account)
            ->reference($order)
            ->currency('USD');
        $b1->insert(new Payment(amount: 10000));
        $b1->insert(new EvidenceFee);
        $b1->insert(new EvidenceFeeTax);
        $b1->save();

        $b2 = Balance::for($account)
            ->reference($order)
            ->currency('USD');
        $b2->insert(new EvidenceFeeReversal);
        $b2->save();

        $this->assertDatabaseCount('balance_transactions', 4);
    }

    public function test_save_without_reference_throws(): void
    {
        $account = $this->createAccount();

        $balance = Balance::for($account)->currency('USD');

        $this->expectException(MissingReferenceException::class);
        $balance->insert(new Payment(amount: 3000));
    }

    public function test_get_without_reference_throws(): void
    {
        $account = $this->createAccount();

        $this->expectException(MissingReferenceException::class);
        Balance::for($account)->get();
    }

    public function test_created_at_is_set_automatically(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 1000));
        $balance->save();

        $record = BalanceTransaction::first();
        $this->assertNotNull($record->created_at);
    }

    public function test_non_unique_items_can_be_duplicated(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 1000));
        $balance->insert(new Payment(amount: 2000));
        $balance->save();

        $this->assertCount(2, $balance->items());
        $this->assertDatabaseCount('balance_transactions', 2);
    }

    // -------------------------------------------------------
    // Batch ID & drop batch
    // -------------------------------------------------------

    public function test_batch_id_is_accessible(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $batchId = $balance->batchId();

        $this->assertNotEmpty($batchId);

        $balance->insert(new Payment(amount: 1000));
        $balance->save();

        $this->assertDatabaseHas('balance_transactions', [
            'batch_id' => $batchId,
        ]);
    }

    public function test_drop_batch_removes_all_items(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert(new Payment(amount: 10000));
        $balance->insert(new GatewayFee(amount: 290));
        $balance->insert(new GatewayFeeTax(amount: 52));
        $balance->save();

        $this->assertDatabaseCount('balance_transactions', 3);

        $deleted = Balance::dropBatch($balance->batchId());

        $this->assertEquals(3, $deleted);
        $this->assertDatabaseCount('balance_transactions', 0);
    }

    public function test_drop_batch_only_removes_targeted_batch(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $b1 = Balance::for($account)
            ->reference($order)
            ->currency('USD');
        $b1->insert(new Payment(amount: 10000));
        $b1->insert(new GatewayFee(amount: 290));
        $b1->save();

        $b2 = Balance::for($account)
            ->reference($order)
            ->currency('USD');
        $b2->insert(new Refund(amount: 5000));
        $b2->save();

        $this->assertDatabaseCount('balance_transactions', 3);

        Balance::dropBatch($b1->batchId());

        $this->assertDatabaseCount('balance_transactions', 1);
        $this->assertDatabaseHas('balance_transactions', [
            'batch_id' => $b2->batchId(),
            'key' => Refund::class,
        ]);
    }

    // -------------------------------------------------------
    // Metadata
    // -------------------------------------------------------

    public function test_metadata_is_stored(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $payment = new Payment(amount: 5000);
        $payment->metadata = ['stripe_charge_id' => 'ch_123', 'source' => 'api'];

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert($payment);
        $balance->save();

        $record = BalanceTransaction::first();
        $this->assertEquals(['stripe_charge_id' => 'ch_123', 'source' => 'api'], $record->metadata);
    }

    public function test_metadata_with_fluent_setter(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(
            (new Payment(amount: 5000))->withMetadata(['gateway' => 'stripe', 'charge_id' => 'ch_456'])
        );
        $balance->save();

        $record = BalanceTransaction::first();
        $this->assertEquals(['gateway' => 'stripe', 'charge_id' => 'ch_456'], $record->metadata);
    }

    public function test_save_without_currency_throws(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order);
        $balance->insert(new Payment(amount: 1000));

        $this->expectException(MissingCurrencyException::class);
        $balance->save();
    }

    public function test_metadata_is_loaded_from_database(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $payment = new Payment(amount: 5000);
        $payment->metadata = ['gateway' => 'stripe'];
        $balance->insert($payment);
        $balance->save();

        $loaded = Balance::for($account)->reference($order)->get();
        $item = $loaded->items()->first();
        $this->assertEquals(['gateway' => 'stripe'], $item->metadata);
    }

    // -------------------------------------------------------
    // fresh()
    // -------------------------------------------------------

    public function test_fresh_returns_new_balance_with_same_context(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $b1 = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $b1->insert(new Payment(amount: 10000));
        $b1->save();

        $b2 = $b1->fresh();
        $b2->insert(new Refund(amount: 5000));
        $b2->save();

        $this->assertNotEquals($b1->batchId(), $b2->batchId());
        $this->assertDatabaseCount('balance_transactions', 2);
    }

    // -------------------------------------------------------
    // InvalidAmountException
    // -------------------------------------------------------

    public function test_negative_amount_throws_invalid_amount_exception(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $negativeItem = new class extends BalanceItem
        {
            public function amount(): int
            {
                return -100;
            }

            public function direction(): Direction
            {
                return Direction::Debit;
            }
        };

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert($negativeItem);

        $this->expectException(InvalidAmountException::class);
        $balance->save();
    }

    // -------------------------------------------------------
    // BalanceItemNotFoundException
    // -------------------------------------------------------

    public function test_amount_of_nonexistent_item_throws(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)
            ->reference($order)
            ->currency('USD');

        $balance->insert(new Payment(amount: 5000));

        $this->expectException(BalanceItemNotFoundException::class);
        $balance->amountOf(GatewayFee::class);
    }

    public function test_get_returns_cached_on_second_call(): void
    {
        $account = $this->createAccount();
        $order = $this->createOrder($account);

        $balance = Balance::for($account)->reference($order)->currency('USD');
        $balance->insert(new Payment(amount: 5000));
        $balance->save();

        $loaded = Balance::for($account)->reference($order)->get();
        $this->assertCount(1, $loaded->items());

        $loadedAgain = $loaded->get();
        $this->assertSame($loaded, $loadedAgain);
        $this->assertCount(1, $loadedAgain->items());
    }
}
