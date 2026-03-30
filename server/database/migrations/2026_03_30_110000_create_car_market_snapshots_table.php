<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('external_id');
            $table->string('source');
            $table->string('brand')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->integer('year')->nullable()->index();
            $table->string('title');
            $table->text('url');
            $table->string('category')->nullable();
            $table->string('region')->nullable()->index();
            $table->decimal('price', 12, 2)->nullable()->index();
            $table->string('price_currency')->nullable();
            $table->string('price_evaluation')->nullable();
            $table->integer('km')->nullable();
            $table->string('fuel')->nullable()->index();
            $table->string('gearbox')->nullable()->index();
            $table->integer('power_hp')->nullable();
            $table->string('color')->nullable();
            $table->integer('doors')->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_market_snapshots');
    }
};
