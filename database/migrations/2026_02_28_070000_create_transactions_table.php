<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('type', ['trade', 'swap', 'deposit', 'withdrawal']);
            $table->string('wallet_address')->index();
            $table->foreignId('chain_id')->nullable()->constrained('chains')->nullOnDelete();
            $table->string('from_token')->nullable();
            $table->string('to_token')->nullable();
            $table->decimal('from_amount', 30, 18);
            $table->decimal('to_amount', 30, 18)->nullable();
            $table->decimal('fee_amount', 30, 18)->default(0);
            $table->string('fee_currency')->nullable();
            $table->string('tx_hash')->nullable()->index();
            $table->enum('status', ['pending', 'confirming', 'completed', 'failed', 'cancelled']);
            $table->bigInteger('block_number')->nullable();
            $table->bigInteger('gas_used')->nullable();
            $table->string('gas_price')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['wallet_address', 'type']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
