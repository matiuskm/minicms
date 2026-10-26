<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Posts in such a category stay out of the home list, /blog and the RSS feed; its own page still lists them.
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('hide_from_blog')->default(false)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('hide_from_blog');
        });
    }
};
