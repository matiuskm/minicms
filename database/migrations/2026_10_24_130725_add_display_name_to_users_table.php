<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public byline. Login is by email, so neither the email nor the real name has to be shown to visitors.
        Schema::table('users', function (Blueprint $table) {
            $table->string('display_name', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
