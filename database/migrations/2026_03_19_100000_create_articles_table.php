<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPIX TRADE — Articles table
 * ระบบบทความ AI-generated + manual สำหรับ Content Marketing
 * รองรับหลายภาษา, AI image, scheduled publishing
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->string('cover_image')->nullable();
            $table->string('language', 5)->default('th');
            $table->string('category')->default('news');
            $table->json('tags')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('ai_model')->nullable();
            $table->integer('ai_tokens_used')->default(0);
            $table->string('ai_image_prompt')->nullable();
            $table->integer('views')->default(0);
            $table->integer('likes')->default(0);
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('author_name')->default('TPIX TRADE');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['language', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
