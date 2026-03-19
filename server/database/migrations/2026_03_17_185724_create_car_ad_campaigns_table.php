<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('car_ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30)->comment('meta, google')->default('meta');

            // Hierarquia Meta: campanha → conjunto → anúncio
            $table->string('campaign_id', 50);
            $table->string('campaign_name', 255)->nullable();
            $table->string('adset_id', 50)->comment('nível principal do mapeamento')->nullable();
            $table->string('adset_name', 255)->nullable();
            $table->string('ad_id', 50)->comment('opcional — granularidade máxima')->nullable();
            $table->string('ad_name', 255)->nullable();

            // Nível de mapeamento — onde os dados são agregados
            $table->enum('level', ['campaign', 'adset', 'ad'])->default('adset');

            // Para 1 adset com vários carros — split proporcional do spend
            // Ex: 2 carros no mesmo adset → 50% cada
            $table->decimal('spend_split_pct', 5, 2)->default(100.00);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Um adset pode estar mapeado a vários carros (split)
            // mas um carro não deve ter o mesmo adset mapeado duas vezes
            $table->unique(['company_id', 'car_id', 'platform', 'adset_id'], 'unique_car_adset');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_ad_campaigns');
    }
};
