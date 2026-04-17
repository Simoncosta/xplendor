<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_ad_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->string('dealer_id', 50)->nullable();
            $table->string('source', 30)->default('unknown');

            $table->string('platform', 30)->default('meta');
            $table->string('campaign_id', 50)->nullable();
            $table->string('adset_id', 50)->nullable();
            $table->string('ad_id', 50)->nullable();

            $table->uuid('visitor_id')->nullable();
            $table->uuid('session_id')->nullable();

            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_id')->nullable();

            $table->string('click_id', 255)->nullable();

            $table->timestamp('first_interaction_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();

            $table->boolean('has_whatsapp_click')->default(false);
            $table->boolean('has_lead')->default(false);
            $table->boolean('has_strong_intent')->default(false);

            $table->timestamps();

            $table->unique(
                ['company_id', 'car_id', 'visitor_id', 'session_id'],
                'car_ad_attr_company_car_visitor_session_unique'
            );
            $table->index(['company_id', 'car_id', 'visitor_id'], 'car_ad_attr_company_car_visitor_idx');
            $table->index(['company_id', 'car_id', 'session_id'], 'car_ad_attr_company_car_session_idx');
            $table->index(['company_id', 'car_id', 'campaign_id'], 'car_ad_attr_company_car_campaign_idx');
            $table->index(['company_id', 'car_id', 'adset_id'], 'car_ad_attr_company_car_adset_idx');
            $table->index(['company_id', 'car_id', 'ad_id'], 'car_ad_attr_company_car_ad_idx');
            $table->index(['company_id', 'car_id', 'utm_id'], 'car_ad_attr_company_car_utm_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_ad_attributions');
    }
};
