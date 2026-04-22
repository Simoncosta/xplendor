<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cars', 'sold_at')) {
            Schema::table('cars', function (Blueprint $table) {
                $table->timestamp('sold_at')->nullable()->after('status')->index();
            });
        }

        Schema::create('car_sales_learning', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sold_at');
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->unsignedTinyInteger('buyer_age')->nullable();
            $table->string('buyer_gender', 20)->nullable();
            $table->unsignedTinyInteger('contact_signal_score')->default(0);
            $table->string('contact_signal_level', 30)->nullable();
            $table->unsignedTinyInteger('peak_contact_signal_last_7d')->default(0);
            $table->timestamp('peak_contact_signal_at')->nullable();
            $table->enum('contact_signal_trend', ['up', 'stable', 'down'])->default('stable');
            $table->unsignedInteger('sessions_last_7d')->default(0);
            $table->unsignedInteger('views_last_7d')->default(0);
            $table->unsignedInteger('whatsapp_clicks_last_7d')->default(0);
            $table->unsignedInteger('leads_last_7d')->default(0);
            $table->enum('primary_contact_channel', ['whatsapp', 'call', 'form', 'unknown'])->default('unknown');
            $table->json('campaign_ids')->nullable();
            $table->json('ad_ids')->nullable();
            $table->json('adset_ids')->nullable();
            $table->decimal('price_at_sale', 12, 2)->nullable();
            $table->unsignedInteger('days_in_stock')->nullable();
            $table->unsignedTinyInteger('sale_quality_score')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'car_id', 'sold_at'], 'car_sales_learning_sale_unique');
            $table->index(['company_id', 'sold_at'], 'car_sales_learning_company_sold_idx');
        });

        Schema::create('car_sale_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sold_at');
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('attributed_platform', 30)->nullable();
            $table->string('attributed_campaign_id', 50)->nullable();
            $table->string('attributed_adset_id', 50)->nullable();
            $table->string('attributed_ad_id', 50)->nullable();
            $table->string('attribution_model', 50)->default('last_touch_recent_window');
            $table->unsignedTinyInteger('attribution_window_days')->default(7);
            $table->enum('match_type', ['direct_ad', 'adset_match', 'campaign_match', 'fallback'])->default('fallback');
            $table->unsignedInteger('time_to_sale_hours')->nullable();
            $table->unsignedInteger('time_from_last_interaction_hours')->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->string('confidence_reason')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'car_id', 'sold_at'], 'car_sale_attr_sale_unique');
            $table->index(['company_id', 'attributed_campaign_id'], 'car_sale_attr_company_campaign_idx');
            $table->index(['company_id', 'attributed_adset_id'], 'car_sale_attr_company_adset_idx');
            $table->index(['company_id', 'attributed_ad_id'], 'car_sale_attr_company_ad_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_sale_attributions');
        Schema::dropIfExists('car_sales_learning');

        if (Schema::hasColumn('cars', 'sold_at')) {
            Schema::table('cars', function (Blueprint $table) {
                $table->dropIndex(['sold_at']);
                $table->dropColumn('sold_at');
            });
        }
    }
};
