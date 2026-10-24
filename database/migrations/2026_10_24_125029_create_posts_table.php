<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('featured_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->string('excerpt', 500)->nullable();
            $table->mediumText('body');
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->enum('status', ['draft', 'pending_review', 'rejected', 'scheduled', 'published', 'archived'])->default('draft');
            $table->string('review_note', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->char('content_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'published_at'], 'posts_status_pub');
            $table->index(['category_id', 'status', 'published_at'], 'posts_cat_status_pub');
            $table->index(['author_id', 'status'], 'posts_author_status');
            $table->fullText(['title', 'excerpt', 'body'], 'posts_ft');
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
            $table->index('tag_id', 'post_tag_tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('posts');
    }
};
