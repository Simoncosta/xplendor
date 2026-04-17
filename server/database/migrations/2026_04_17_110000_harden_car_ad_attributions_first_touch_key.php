<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('car_ad_attributions')) {
            return;
        }

        Schema::table('car_ad_attributions', function (Blueprint $table) {
            if (!Schema::hasColumn('car_ad_attributions', 'source')) {
                $table->string('source', 30)->default('unknown')->after('dealer_id');
            }
        });

        try {
            Schema::table('car_ad_attributions', function (Blueprint $table) {
                $table->dropUnique('car_ad_attr_company_car_visitor_session_unique');
            });
        } catch (\Throwable $e) {
        }

        Schema::table('car_ad_attributions', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'car_id', 'visitor_id', 'session_id'],
                'car_ad_attr_company_car_visitor_session_unique'
            );
        });

        DB::table('car_ad_attributions')
            ->whereNull('source')
            ->update(['source' => 'unknown']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('car_ad_attributions')) {
            return;
        }

        try {
            Schema::table('car_ad_attributions', function (Blueprint $table) {
                $table->dropUnique('car_ad_attr_company_car_visitor_session_unique');
            });
        } catch (\Throwable $e) {
        }
    }
};
