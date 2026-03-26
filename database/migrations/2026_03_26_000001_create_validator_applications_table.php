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
        Schema::create('validator_applications', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address')->unique()->index();
            $table->enum('tier', ['validator', 'sentinel', 'light']);
            $table->string('endpoint')->nullable()->comment('RPC endpoint URL');
            $table->string('country_code', 2)->index();
            $table->string('country_name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('contact_email')->nullable();
            $table->string('contact_telegram')->nullable();
            $table->text('hardware_specs')->nullable()->comment('JSON string of CPU/RAM/SSD');
            $table->text('motivation')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'active'])->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('admin_users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validator_applications');
    }
};
