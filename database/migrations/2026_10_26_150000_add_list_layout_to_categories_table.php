<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The category page shows one wide card per row (image beside text) instead of the three-column grid.
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('list_layout')->default(false)->after('hide_from_blog');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('list_layout');
        });
    }
};
