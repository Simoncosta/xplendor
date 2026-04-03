<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_audience_insights', function (Blueprint $table) {
            $table->json('campaign_targeting_json')->nullable()->after('reach');
        });
    }

    public function down(): void
    {
        Schema::table('meta_audience_insights', function (Blueprint $table) {
            $table->dropColumn('campaign_targeting_json');
        });
    }
};
