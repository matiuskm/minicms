<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Browsers a user has signed in from, so a login from a new one can be flagged by email (FR-69).
        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->string('label', 120)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
    }
};
