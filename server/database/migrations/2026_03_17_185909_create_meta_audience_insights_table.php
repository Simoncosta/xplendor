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
        Schema::create('meta_audience_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            // Breakdown por faixa etária + género
            $table->string('age_range', 20)->comment('18-24, 25-34, 35-44, 45-54, 55-64, 65+');
            $table->string('gender', 10)->comment('male, female, unknown');

            // Métricas para esta combinação
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->decimal('spend', 10, 2)->default(0);
            $table->integer('reach')->default(0);

            $table->timestamps();

            $table->unique(
                ['company_id', 'car_id', 'period_start', 'period_end', 'age_range', 'gender'],
                'unique_audience_period'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_audience_insights');
    }
};
