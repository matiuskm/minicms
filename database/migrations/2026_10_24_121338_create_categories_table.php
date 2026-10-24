<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // Fallback category (BR-18); cannot be deleted.
        DB::table('categories')->insert([
            'name' => 'Uncategorized', 'slug' => 'uncategorized', 'is_system' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
