<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Hub do Automóvel: o view car_funnel_metrics_daily passa a ter CONTACTOS.
 *
 * Contactos = whatsapp_click + call_click + show_phone + copy_phone (antes o view só
 * tinha whatsapp_clicks e form_opens). As colunas existentes ficam IGUAIS (mesmos
 * nomes, mesma conta); acrescentam-se no fim: call_clicks, phone_reveals
 * (show_phone + copy_phone) e contacts.
 *
 * O SQL é gerado por driver: em MySQL/MariaDB JSON_UNQUOTE(JSON_EXTRACT(...)); em
 * sqlite (testes) json_extract(...). O view antigo só existia na versão MySQL e não
 * se podia consultar em sqlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS car_funnel_metrics_daily');
        DB::statement($this->viewSql(true));
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS car_funnel_metrics_daily');
        DB::statement($this->viewSql(false));
    }

    private function jsonValue(string $path): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "json_extract(meta, '{$path}')"
            : "JSON_UNQUOTE(JSON_EXTRACT(meta, '{$path}'))";
    }

    private function viewSql(bool $withContacts): string
    {
        $scrollPct = $this->jsonValue('$.scroll_pct');
        $scrollDepth = $this->jsonValue('$.scroll_depth');

        $contactCols = $withContacts ? ",
    COALESCE(interactions.call_clicks, 0) AS call_clicks,
    COALESCE(interactions.phone_reveals, 0) AS phone_reveals,
    COALESCE(interactions.contacts, 0) AS contacts" : '';

        $contactAgg = $withContacts ? ",
        SUM(CASE WHEN interaction_type = 'call_click' THEN 1 ELSE 0 END) AS call_clicks,
        SUM(CASE WHEN interaction_type IN ('show_phone', 'copy_phone') THEN 1 ELSE 0 END) AS phone_reveals,
        SUM(CASE WHEN interaction_type IN ('whatsapp_click', 'call_click', 'show_phone', 'copy_phone') THEN 1 ELSE 0 END) AS contacts" : '';

        return <<<SQL
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
    COALESCE(leads.leads, 0) AS leads{$contactCols}
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
                            {$scrollPct},
                            {$scrollDepth}
                        ) AS DECIMAL(10, 2)
                    )
                ELSE NULL
            END
        ), 2) AS scroll{$contactAgg}
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
SQL;
    }
};
