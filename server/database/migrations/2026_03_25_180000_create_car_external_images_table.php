<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_external_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained();
            $table->string('source', 50);
            $table->text('external_url');
            $table->integer('external_index')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->nullable();
            $table->timestamps();

            $table->index('car_id');
            $table->index('company_id');
            $table->index('source');
            $table->index(['car_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_external_images');
    }
};
