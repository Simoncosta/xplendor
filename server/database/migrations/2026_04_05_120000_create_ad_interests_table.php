<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_interests', function (Blueprint $table) {
            $table->id();
            $table->string('meta_id')->unique();
            $table->string('name')->index();
            $table->unsignedBigInteger('audience_size')->nullable();
            $table->string('topic')->nullable();
            $table->json('path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_interests');
    }
};
