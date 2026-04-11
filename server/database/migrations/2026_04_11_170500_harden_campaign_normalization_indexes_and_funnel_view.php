<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_views', function (Blueprint $table) {
            $table->index(
                ['company_id', 'car_id', 'session_id', 'created_at'],
                'car_views_company_car_session_created_idx'
            );
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
        ROUND(AVG(COALESCE(view_duration_seconds, 0)), 2) AS avg_time_on_page
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
                WHEN interaction_type IN ('scroll', 'scroll_depth')
                    THEN CAST(
                        COALESCE(
                            JSON_UNQUOTE(JSON_EXTRACT(meta, '$.scroll_pct')),
                            JSON_UNQUOTE(JSON_EXTRACT(meta, '$.scroll_depth'))
                        ) AS DECIMAL(10, 2)
                    )
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

        Schema::table('car_views', function (Blueprint $table) {
            $table->dropIndex('car_views_company_car_session_created_idx');
        });

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
};
