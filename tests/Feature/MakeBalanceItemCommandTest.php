<?php

declare(strict_types=1);

namespace Laratusk\Cashflow\Tests\Feature;

use Illuminate\Support\Facades\File;
use Laratusk\Cashflow\Tests\TestCase;

class MakeBalanceItemCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = app_path('Ledgers');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function test_creates_external_amount_item(): void
    {
        $this->artisan('make:balance-item')
            ->expectsQuestion('Class name', 'Payment')
            ->expectsQuestion('Direction', 'credit')
            ->expectsQuestion('How is the amount determined?', 'external')
            ->expectsQuestion('Unique per reference? (#[Unique])', false)
            ->assertSuccessful();

        $path = "{$this->basePath}/Payment.php";
        $this->assertFileExists($path);

        $content = File::get($path);
        $this->assertStringContainsString('namespace App\\Ledgers;', $content);
        $this->assertStringContainsString('final class Payment extends BalanceItem', $content);
        $this->assertStringContainsString('private readonly int $amount', $content);
        $this->assertStringNotContainsString('public function amount()', $content);
        $this->assertStringContainsString('Direction::Credit', $content);
        $this->assertStringContainsString("#[Rule('required', 'integer', 'min:1')]", $content);
        $this->assertStringNotContainsString('#[Unique]', $content);
    }

    public function test_creates_fixed_amount_item(): void
    {
        $this->artisan('make:balance-item')
            ->expectsQuestion('Class name', 'EvidenceFee')
            ->expectsQuestion('Direction', 'debit')
            ->expectsQuestion('How is the amount determined?', 'fixed')
            ->expectsQuestion('Fixed amount (in minor units, e.g. 2000 for $20.00)', '2000')
            ->expectsQuestion('Unique per reference? (#[Unique])', true)
            ->assertSuccessful();

        $path = "{$this->basePath}/EvidenceFee.php";
        $content = File::get($path);

        $this->assertStringContainsString('#[Unique]', $content);
        $this->assertStringContainsString('return 2000;', $content);
        $this->assertStringContainsString('Direction::Debit', $content);
        $this->assertStringNotContainsString('__construct', $content);
    }

    public function test_creates_derived_amount_item(): void
    {
        $this->artisan('make:balance-item')
            ->expectsQuestion('Class name', 'GatewayFee')
            ->expectsQuestion('Direction', 'debit')
            ->expectsQuestion('How is the amount determined?', 'derived')
            ->expectsQuestion('Unique per reference? (#[Unique])', true)
            ->assertSuccessful();

        $path = "{$this->basePath}/GatewayFee.php";
        $content = File::get($path);

        $this->assertStringContainsString('#[Unique]', $content);
        $this->assertStringContainsString('private readonly float $rate', $content);
        $this->assertStringContainsString('$this->balance()->amountOf(', $content);
        $this->assertStringContainsString('$this->rate / 100', $content);
    }

    public function test_creates_item_with_requires(): void
    {
        File::ensureDirectoryExists($this->basePath);
        File::put("{$this->basePath}/EvidenceFee.php", '<?php // stub');

        $this->artisan('make:balance-item')
            ->expectsQuestion('Class name', 'EvidenceFeeReversal')
            ->expectsQuestion('Direction', 'credit')
            ->expectsQuestion('How is the amount determined?', 'fixed')
            ->expectsQuestion('Fixed amount (in minor units, e.g. 2000 for $20.00)', '2000')
            ->expectsQuestion('Unique per reference? (#[Unique])', true)
            ->expectsQuestion('Requires another BalanceItem?', 'App\\Ledgers\\EvidenceFee')
            ->assertSuccessful();

        $path = "{$this->basePath}/EvidenceFeeReversal.php";
        $content = File::get($path);

        $this->assertStringContainsString('#[Unique]', $content);
        $this->assertStringContainsString('#[Requires(EvidenceFee::class)]', $content);
        $this->assertStringContainsString('use App\\Ledgers\\EvidenceFee;', $content);
        $this->assertStringContainsString('Direction::Credit', $content);
        $this->assertStringContainsString('return 2000;', $content);
    }

    public function test_creates_directory_if_not_exists(): void
    {
        File::deleteDirectory($this->basePath);

        $this->artisan('make:balance-item')
            ->expectsQuestion('Class name', 'TestItem')
            ->expectsQuestion('Direction', 'debit')
            ->expectsQuestion('How is the amount determined?', 'fixed')
            ->expectsQuestion('Fixed amount (in minor units, e.g. 2000 for $20.00)', '100')
            ->expectsQuestion('Unique per reference? (#[Unique])', false)
            ->assertSuccessful();

        $this->assertFileExists("{$this->basePath}/TestItem.php");
    }

    public function test_lists_existing_items_for_requires(): void
    {
        File::ensureDirectoryExists($this->basePath);
        File::put("{$this->basePath}/Payment.php", '<?php // stub');
        File::put("{$this->basePath}/GatewayFee.php", '<?php // stub');

        $this->artisan('make:balance-item')
            ->expectsQuestion('Class name', 'Refund')
            ->expectsQuestion('Direction', 'debit')
            ->expectsQuestion('How is the amount determined?', 'external')
            ->expectsQuestion('Unique per reference? (#[Unique])', false)
            ->expectsQuestion('Requires another BalanceItem?', 'App\\Ledgers\\Payment')
            ->assertSuccessful();

        $content = File::get("{$this->basePath}/Refund.php");
        $this->assertStringContainsString('#[Requires(Payment::class)]', $content);
        $this->assertStringContainsString('use App\\Ledgers\\Payment;', $content);
    }
}
