<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->enum('location', ['header', 'footer']);
            $table->string('label', 60);
            $table->enum('type', ['page', 'category', 'url']);
            $table->foreignId('page_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['location', 'sort_order'], 'menu_loc_order');
        });

        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT chk_menu_target CHECK (
            (type = 'page' AND page_id IS NOT NULL) OR (type = 'category' AND category_id IS NOT NULL) OR (type = 'url' AND url IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
