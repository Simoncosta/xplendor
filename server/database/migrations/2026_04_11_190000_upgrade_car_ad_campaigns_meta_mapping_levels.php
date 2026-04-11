<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_ad_campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('car_ad_campaigns', 'ad_id')) {
                $table->string('ad_id', 50)->nullable()->after('adset_name');
            }

            if (!Schema::hasColumn('car_ad_campaigns', 'ad_name')) {
                $table->string('ad_name', 255)->nullable()->after('ad_id');
            }

            if (!Schema::hasColumn('car_ad_campaigns', 'level')) {
                $table->enum('level', ['campaign', 'adset', 'ad'])->default('adset')->after('ad_name');
            }
        });

        Schema::table('car_ad_campaigns', function (Blueprint $table) {
            $table->dropUnique('unique_car_adset');
            $table->index(['company_id', 'car_id', 'platform', 'campaign_id'], 'car_ad_campaigns_company_car_platform_campaign_idx');
            $table->index(['company_id', 'car_id', 'platform', 'adset_id'], 'car_ad_campaigns_company_car_platform_adset_idx');
            $table->index(['company_id', 'car_id', 'platform', 'ad_id'], 'car_ad_campaigns_company_car_platform_ad_idx');
        });
    }

    public function down(): void
    {
        Schema::table('car_ad_campaigns', function (Blueprint $table) {
            $table->dropIndex('car_ad_campaigns_company_car_platform_campaign_idx');
            $table->dropIndex('car_ad_campaigns_company_car_platform_adset_idx');
            $table->dropIndex('car_ad_campaigns_company_car_platform_ad_idx');
            $table->unique(['company_id', 'car_id', 'platform', 'adset_id'], 'unique_car_adset');
        });
    }
};
