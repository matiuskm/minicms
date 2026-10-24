<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, string>> background, surface, text, muted, primary, accent, border, link */
    private const PRESETS = [
        'Light Classic' => ['background' => '#f1e5da', 'surface' => '#ffffff', 'text' => '#1d1b19', 'muted' => '#7a726b', 'primary' => '#8f3f1f', 'accent' => '#f7ede5', 'border' => '#ece4dc', 'link' => '#8f3f1f'],
        'Dark' => ['background' => '#0f1115', 'surface' => '#181b21', 'text' => '#eceff4', 'muted' => '#9aa3b2', 'primary' => '#6ea8fe', 'accent' => '#232833', 'border' => '#2a303b', 'link' => '#8ab8ff'],
        'Ocean' => ['background' => '#e6f0f7', 'surface' => '#ffffff', 'text' => '#0f2b3d', 'muted' => '#587083', 'primary' => '#0b6fa4', 'accent' => '#e9f4fb', 'border' => '#d3e3ee', 'link' => '#0b6fa4'],
        'Forest' => ['background' => '#e8efe6', 'surface' => '#ffffff', 'text' => '#17261b', 'muted' => '#566b59', 'primary' => '#2f6b3f', 'accent' => '#eef5ec', 'border' => '#d5e2d3', 'link' => '#2f6b3f'],
        'Sunset' => ['background' => '#fbe9e0', 'surface' => '#fffaf7', 'text' => '#2a1612', 'muted' => '#7d584e', 'primary' => '#c2410c', 'accent' => '#fff0e8', 'border' => '#f1d4c6', 'link' => '#b43a0a'],
        'Monochrome' => ['background' => '#eeeeee', 'surface' => '#ffffff', 'text' => '#111111', 'muted' => '#636363', 'primary' => '#111111', 'accent' => '#f3f3f3', 'border' => '#dddddd', 'link' => '#111111'],
    ];

    public function up(): void
    {
        Schema::create('palettes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('is_preset')->default(false);
            $table->boolean('is_active')->default(false);
            // NULL unless active, so a unique index allows exactly one active palette (BR-27).
            $table->tinyInteger('active_flag')->nullable()->storedAs('IF(is_active = 1, 1, NULL)')->unique();
            $table->json('tokens');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (self::PRESETS as $name => $tokens) {
            DB::table('palettes')->insert([
                'name' => $name, 'is_preset' => true, 'is_active' => $name === 'Light Classic',
                'tokens' => json_encode($tokens), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('palettes');
    }
};
