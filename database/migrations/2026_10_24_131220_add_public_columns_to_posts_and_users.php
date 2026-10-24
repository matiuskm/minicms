<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedTinyInteger('reading_minutes')->default(1)->after('body');
        });

        // Public author archive address: derived from the display name, never the id or the login email.
        Schema::table('users', function (Blueprint $table) {
            $table->string('author_slug', 80)->nullable()->unique()->after('display_name');
        });

        $taken = [];
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'display_name']) as $user) {
            $base = Str::slug($user->display_name ?: $user->name) ?: 'author';
            $slug = $base;
            for ($i = 2; in_array($slug, $taken, true); $i++) {
                $slug = "{$base}-{$i}";
            }
            $taken[] = $slug;
            DB::table('users')->where('id', $user->id)->update(['author_slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['author_slug']);
            $table->dropColumn('author_slug');
        });
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('reading_minutes');
        });
    }
};
