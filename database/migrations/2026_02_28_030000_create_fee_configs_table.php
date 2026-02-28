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
        Schema::create('fee_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['trading', 'swap', 'withdrawal', 'deposit']);
            $table->decimal('maker_fee', 8, 4)->default(0.1);
            $table->decimal('taker_fee', 8, 4)->default(0.1);
            $table->decimal('min_amount', 20, 8)->nullable();
            $table->decimal('max_amount', 20, 8)->nullable();
            $table->unsignedBigInteger('chain_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_configs');
    }
};
