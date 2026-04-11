<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_car_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mapping_id')->constrained('car_ad_campaigns')->cascadeOnDelete();
            $table->string('campaign_id', 50);
            $table->string('adset_id', 50)->nullable();
            $table->date('date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('spend_normalized', 10, 2)->default(0);
            $table->decimal('ctr', 5, 2)->nullable();
            $table->decimal('cpc', 10, 4)->nullable();
            $table->decimal('cpm', 10, 2)->nullable();
            $table->decimal('allocation_factor', 8, 6)->default(1);
            $table->timestamps();

            $table->unique(['mapping_id', 'date'], 'campaign_car_metrics_daily_mapping_date_uq');
            $table->index(['company_id', 'car_id', 'date'], 'campaign_car_metrics_daily_company_car_date_idx');
            $table->index(['company_id', 'campaign_id', 'date'], 'campaign_car_metrics_daily_company_campaign_date_idx');
        });

        Schema::table('car_ad_campaigns', function (Blueprint $table) {
            $table->index(
                ['company_id', 'platform', 'is_active', 'adset_id'],
                'car_ad_campaigns_company_platform_active_adset_idx'
            );
            $table->index(
                ['company_id', 'car_id', 'is_active'],
                'car_ad_campaigns_company_car_active_idx'
            );
        });

        Schema::table('meta_audience_insights', function (Blueprint $table) {
            $table->index(
                ['company_id', 'car_id', 'period_start', 'period_end'],
                'meta_audience_insights_company_car_period_idx'
            );
        });

        Schema::table('car_leads', function (Blueprint $table) {
            $table->index(['company_id', 'car_id', 'created_at'], 'car_leads_company_car_created_idx');
            $table->index(['company_id', 'session_id', 'created_at'], 'car_leads_company_session_created_idx');
        });

        DB::statement('DROP VIEW IF EXISTS car_funnel_metrics_daily');
        DB::statement(<<<'SQL'
CREATE VIEW car_funnel_metrics_daily AS
SELECT
    base.company_id,
    base.car_id,
    base.metric_date AS date,
    COALESCE(sessions.sessions, 0) AS sessions,
    COALESCE(views.views, 0) AS views,
    views.avg_time_on_page,
    interactions.scroll,
    COALESCE(interactions.whatsapp_clicks, 0) AS whatsapp_clicks,
    COALESCE(interactions.form_opens, 0) AS form_opens,
    COALESCE(leads.leads, 0) AS leads
FROM (
    SELECT company_id, car_id, DATE(created_at) AS metric_date
    FROM car_views
    GROUP BY company_id, car_id, DATE(created_at)
    UNION
    SELECT company_id, car_id, DATE(created_at) AS metric_date
    FROM car_interactions
    WHERE car_id IS NOT NULL
    GROUP BY company_id, car_id, DATE(created_at)
    UNION
    SELECT company_id, car_id, DATE(created_at) AS metric_date
    FROM car_leads
    GROUP BY company_id, car_id, DATE(created_at)
) AS base
LEFT JOIN (
    SELECT
        company_id,
        car_id,
        DATE(created_at) AS metric_date,
        COUNT(*) AS views,
        ROUND(AVG(view_duration_seconds), 2) AS avg_time_on_page
    FROM car_views
    GROUP BY company_id, car_id, DATE(created_at)
) AS views
    ON views.company_id = base.company_id
   AND views.car_id = base.car_id
   AND views.metric_date = base.metric_date
LEFT JOIN (
    SELECT
        company_id,
        car_id,
        DATE(created_at) AS metric_date,
        SUM(CASE WHEN interaction_type = 'whatsapp_click' THEN 1 ELSE 0 END) AS whatsapp_clicks,
        SUM(CASE WHEN interaction_type IN ('form_open', 'form_start') THEN 1 ELSE 0 END) AS form_opens,
        ROUND(AVG(
            CASE
                WHEN interaction_type IN ('scroll', 'scroll_depth') THEN
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.scroll_pct')) AS DECIMAL(10, 2))
                ELSE NULL
            END
        ), 2) AS scroll
    FROM car_interactions
    WHERE car_id IS NOT NULL
    GROUP BY company_id, car_id, DATE(created_at)
) AS interactions
    ON interactions.company_id = base.company_id
   AND interactions.car_id = base.car_id
   AND interactions.metric_date = base.metric_date
LEFT JOIN (
    SELECT
        company_id,
        car_id,
        DATE(created_at) AS metric_date,
        COUNT(*) AS leads
    FROM car_leads
    GROUP BY company_id, car_id, DATE(created_at)
) AS leads
    ON leads.company_id = base.company_id
   AND leads.car_id = base.car_id
   AND leads.metric_date = base.metric_date
LEFT JOIN (
    SELECT
        unioned.company_id,
        unioned.car_id,
        unioned.metric_date,
        COUNT(DISTINCT unioned.session_id) AS sessions
    FROM (
        SELECT company_id, car_id, DATE(created_at) AS metric_date, session_id
        FROM car_views
        WHERE session_id IS NOT NULL
        UNION ALL
        SELECT company_id, car_id, DATE(created_at) AS metric_date, session_id
        FROM car_interactions
        WHERE car_id IS NOT NULL AND session_id IS NOT NULL
        UNION ALL
        SELECT company_id, car_id, DATE(created_at) AS metric_date, session_id
        FROM car_leads
        WHERE session_id IS NOT NULL
    ) AS unioned
    GROUP BY unioned.company_id, unioned.car_id, unioned.metric_date
) AS sessions
    ON sessions.company_id = base.company_id
   AND sessions.car_id = base.car_id
   AND sessions.metric_date = base.metric_date
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS car_funnel_metrics_daily');

        Schema::table('car_leads', function (Blueprint $table) {
            $table->dropIndex('car_leads_company_car_created_idx');
            $table->dropIndex('car_leads_company_session_created_idx');
        });

        Schema::table('meta_audience_insights', function (Blueprint $table) {
            $table->dropIndex('meta_audience_insights_company_car_period_idx');
        });

        Schema::table('car_ad_campaigns', function (Blueprint $table) {
            $table->dropIndex('car_ad_campaigns_company_platform_active_adset_idx');
            $table->dropIndex('car_ad_campaigns_company_car_active_idx');
        });

        Schema::dropIfExists('campaign_car_metrics_daily');
    }
};
