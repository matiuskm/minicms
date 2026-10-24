<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // revisionable_type holds the morph alias ('post' | 'page'), see AppServiceProvider.
        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->enum('revisionable_type', ['post', 'page']);
            $table->unsignedBigInteger('revisionable_id');
            $table->foreignId('editor_id')->constrained('users');
            $table->string('title', 200);
            $table->mediumText('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['revisionable_type', 'revisionable_id', 'created_at'], 'revisions_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
