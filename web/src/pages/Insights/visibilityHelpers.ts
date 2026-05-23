import type { TabKey } from 'pages/Dashboards/components/marketingRoi.types';

export type { TabKey };

export interface AnalyticsData {
    insights?: Array<{ type: string; title: string; text: string }>;
    stock_intelligence?: {
        opportunities?: unknown[];
        saturated_segments?: unknown[];
    } | null;
    marketing_performance?: {
        views_last_7_days: number;
    } | null;
    marketing_roi?: {
        summary: { total_spend: number };
    } | null;
    ads_priority_ranking?: {
        cars_ranked_for_ads?: unknown[];
    } | null;
    personas?: Array<{ cars_count: number }>;
}

export function hasMeaningfulInsights(analytics: AnalyticsData): boolean {
    return (analytics.insights?.length ?? 0) > 0;
}

export function hasStockIntelligence(analytics: AnalyticsData): boolean {
    return (analytics.stock_intelligence?.opportunities?.length ?? 0) > 0
        || (analytics.stock_intelligence?.saturated_segments?.length ?? 0) > 0;
}

export function hasMarketingOverview(analytics: AnalyticsData): boolean {
    return (analytics.marketing_performance?.views_last_7_days ?? 0) > 0
        || (analytics.marketing_roi?.summary?.total_spend ?? 0) > 0;
}

export function hasAdRanking(analytics: AnalyticsData): boolean {
    return (analytics.ads_priority_ranking?.cars_ranked_for_ads?.length ?? 0) > 0;
}

export function hasPersonaData(analytics: AnalyticsData): boolean {
    return (analytics.personas?.length ?? 0) > 0
        && (analytics.personas ?? []).some(p => p.cars_count > 0);
}

export function hasAnyMarketingData(analytics: AnalyticsData): boolean {
    return hasMarketingOverview(analytics)
        || hasAdRanking(analytics)
        || hasPersonaData(analytics);
}

export function visibleMarketingTabs(analytics: AnalyticsData): TabKey[] {
    const tabs: TabKey[] = [];
    if (hasMarketingOverview(analytics)) tabs.push('overview');
    if (hasAdRanking(analytics)) tabs.push('ranking');
    if (hasPersonaData(analytics)) tabs.push('personas');
    return tabs;
}
