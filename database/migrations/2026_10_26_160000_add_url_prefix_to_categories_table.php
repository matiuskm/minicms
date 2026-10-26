<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Posts in such a category live at /{url_prefix}/{slug} instead of /blog/{slug}, and the category page at /{url_prefix}.
        Schema::table('categories', function (Blueprint $table) {
            $table->string('url_prefix', 40)->nullable()->unique()->after('list_layout');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['url_prefix']);
            $table->dropColumn('url_prefix');
        });
    }
};
