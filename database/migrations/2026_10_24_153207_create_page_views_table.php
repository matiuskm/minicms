<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cookie-less, first-party visit log. No IP or user agent is stored: `visitor` is a one-way hash that
        // changes every day, so it can count unique visitors per day but cannot follow anyone across days.
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->timestamp('at');
            $table->binary('visitor', 8);
            $table->string('page_key', 60);          // home, blog, post:12, page:3, category:news, tag:php, author:ana
            $table->string('source', 20);            // direct, internal, google, instagram, whatsapp, referral, ...
            $table->string('referrer_host', 80)->nullable(); // only for source = referral
            $table->string('medium', 30)->nullable();
            $table->string('campaign', 60)->nullable();

            $table->index(['day', 'source', 'visitor'], 'pv_day_source');
            $table->index(['day', 'page_key', 'visitor'], 'pv_day_page');
            $table->index(['day', 'visitor'], 'pv_day_visitor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
