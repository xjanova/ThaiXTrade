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
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('type'); // ticket_new, ticket_reply, ticket_assigned, system, user_report
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable(); // Extra context (ticket_id, url, etc.)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'read_at']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
