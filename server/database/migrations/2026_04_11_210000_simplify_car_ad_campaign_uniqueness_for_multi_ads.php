<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_ad_campaigns', function (Blueprint $table) {
            $table->dropUnique('unique_car_adset_level');
            $table->dropUnique('unique_car_campaign');

            $table->index(
                ['company_id', 'car_id', 'platform', 'adset_id'],
                'car_ad_campaigns_company_car_platform_adset_idx'
            );
            $table->index(
                ['company_id', 'car_id', 'platform', 'campaign_id'],
                'car_ad_campaigns_company_car_platform_campaign_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('car_ad_campaigns', function (Blueprint $table) {
            $table->dropIndex('car_ad_campaigns_company_car_platform_adset_idx');
            $table->dropIndex('car_ad_campaigns_company_car_platform_campaign_idx');

            $table->unique(
                ['company_id', 'car_id', 'platform', 'adset_id', 'level'],
                'unique_car_adset_level'
            );
            $table->unique(
                ['company_id', 'car_id', 'platform', 'campaign_id', 'level'],
                'unique_car_campaign'
            );
        });
    }
};
