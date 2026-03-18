<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('carbon_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('location');
            $table->string('country', 2);
            $table->enum('project_type', [
                'reforestation', 'renewable_energy', 'methane_capture',
                'ocean_cleanup', 'carbon_capture', 'biodiversity', 'other',
            ]);
            $table->string('standard')->default('VCS');
            $table->string('registry_id')->nullable();
            $table->string('image_url')->nullable();
            $table->decimal('total_credits', 18, 2)->default(0);
            $table->decimal('available_credits', 18, 2)->default(0);
            $table->decimal('retired_credits', 18, 2)->default(0);
            $table->decimal('price_per_credit_usd', 10, 2);
            $table->decimal('price_per_credit_tpix', 18, 4)->nullable();
            $table->integer('vintage_year')->nullable();
            $table->enum('status', ['draft', 'active', 'sold_out', 'expired', 'suspended'])->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('project_type');
            $table->index('country');
        });

        Schema::create('carbon_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carbon_project_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number')->unique();
            $table->string('owner_address', 42);
            $table->decimal('amount', 18, 2);
            $table->decimal('price_paid_usd', 18, 2);
            $table->string('payment_currency', 10)->default('TPIX');
            $table->decimal('payment_amount', 36, 18)->nullable();
            $table->string('tx_hash', 66)->nullable();
            $table->enum('status', ['active', 'retired', 'transferred', 'pending'])->default('pending');
            $table->timestamps();

            $table->index('owner_address');
            $table->index('status');
        });

        Schema::create('carbon_retirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carbon_credit_id')->constrained()->cascadeOnDelete();
            $table->string('retiree_address', 42);
            $table->string('beneficiary_name')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('certificate_hash', 66)->nullable();
            $table->string('tx_hash', 66)->nullable();
            $table->timestamps();

            $table->index('retiree_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carbon_retirements');
        Schema::dropIfExists('carbon_credits');
        Schema::dropIfExists('carbon_projects');
    }
};
