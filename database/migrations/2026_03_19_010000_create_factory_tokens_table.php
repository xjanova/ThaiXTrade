<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('factory_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('symbol', 20);
            $table->unsignedTinyInteger('decimals')->default(18);
            $table->decimal('total_supply', 36, 0);
            $table->string('creator_address', 42);
            $table->string('contract_address', 42)->nullable();
            $table->string('tx_hash', 66)->nullable();
            $table->unsignedBigInteger('chain_id')->default(4289);
            $table->string('logo_url')->nullable();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->enum('token_type', ['standard', 'mintable', 'burnable', 'mintable_burnable'])->default('standard');
            $table->enum('status', ['pending', 'deploying', 'deployed', 'failed', 'rejected'])->default('pending');
            $table->string('reject_reason')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_listed')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('creator_address');
            $table->index('contract_address');
            $table->index('status');
            $table->index('chain_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_tokens');
    }
};
