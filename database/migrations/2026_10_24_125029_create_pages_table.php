<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('pages');
            $table->foreignId('updated_by')->constrained('users');
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->mediumText('body');
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->char('content_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'sort_order'], 'pages_status_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
