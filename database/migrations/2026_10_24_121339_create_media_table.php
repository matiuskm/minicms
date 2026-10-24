<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('uploader_id')->constrained('users');
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('alt_text')->nullable(); // required for images, title for PDFs (validated in app)
            $table->timestamps();

            $table->index(['uploader_id', 'created_at'], 'media_uploader');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
