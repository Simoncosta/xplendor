<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraper_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('source')->index();
            $table->json('filters')->nullable();
            $table->enum('status', ['pending', 'running', 'success', 'failed'])->default('pending')->index();
            $table->enum('mode', ['preview', 'persist'])->default('preview');
            $table->integer('total_raw')->default(0);
            $table->integer('total_normalized')->default(0);
            $table->integer('total_sent')->default(0);
            $table->integer('total_failed')->default(0);
            $table->text('logs_excerpt')->nullable();
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraper_executions');
    }
};
