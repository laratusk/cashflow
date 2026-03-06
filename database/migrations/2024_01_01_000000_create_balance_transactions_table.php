<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->morphs('balanceable');
            $table->nullableMorphs('reference');
            $table->string('direction');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 12, 6)->default(1.0);
            $table->string('key');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['balanceable_type', 'balanceable_id', 'key']);
            $table->index(['balanceable_type', 'balanceable_id', 'reference_type', 'reference_id'], 'bt_balanceable_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_transactions');
    }
};
