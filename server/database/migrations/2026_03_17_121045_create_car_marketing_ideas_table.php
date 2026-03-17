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
        Schema::create('car_marketing_ideas', function (Blueprint $table) {
            $table->id();

            $table->string('content_type')->default('sale');
            $table->date('week_ref')->index();

            $table->string('status')->default('pending');
            $table->string('title')->nullable();
            $table->text('angle')->nullable();
            $table->string('goal')->nullable();
            $table->string('target_audience')->nullable();
            $table->string('cta')->nullable();

            $table->json('content_pillars')->nullable();
            $table->text('why_now')->nullable();
            $table->json('formats')->nullable();
            $table->longText('caption')->nullable();
            $table->json('hooks')->nullable();

            $table->json('source_data')->nullable();
            $table->longText('ai_raw')->nullable();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'car_id', 'week_ref', 'content_type'],
                'uq_company_car_week_type_marketing_idea'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_marketing_ideas');
    }
};
