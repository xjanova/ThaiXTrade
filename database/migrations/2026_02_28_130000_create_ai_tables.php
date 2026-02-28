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
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['market_analysis', 'price_prediction', 'sentiment', 'technical']);
            $table->string('symbol')->nullable();
            $table->string('chain')->nullable();
            $table->text('prompt');
            $table->longText('response');
            $table->string('model')->default('llama-3.3-70b-versatile');
            $table->integer('tokens_used')->nullable();
            $table->integer('processing_time_ms')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->text('summary')->nullable();
            $table->enum('category', [
                'market_update',
                'analysis',
                'defi',
                'nft',
                'regulation',
                'technology',
                'tutorial',
            ]);
            $table->string('language_code')->default('th');
            $table->text('source_prompt')->nullable();
            $table->string('ai_model')->nullable();
            $table->string('featured_image')->nullable();
            $table->json('tags')->nullable();
            $table->enum('status', ['draft', 'review', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('views')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_news');
        Schema::dropIfExists('ai_analyses');
    }
};
