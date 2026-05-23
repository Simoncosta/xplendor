import {
    hasMeaningfulInsights,
    hasStockIntelligence,
    hasMarketingOverview,
    hasAdRanking,
    hasPersonaData,
    visibleMarketingTabs,
    type AnalyticsData,
} from './visibilityHelpers';

const empty: AnalyticsData = {};

describe('hasMeaningfulInsights', () => {
    it('returns true when array has at least one insight', () => {
        const a: AnalyticsData = { insights: [{ type: 'fuel', title: 'Diesel em alta', text: 'Diesel gera 40% das leads.' }] };
        expect(hasMeaningfulInsights(a)).toBe(true);
    });

    it('returns false when array is empty or undefined', () => {
        expect(hasMeaningfulInsights({ insights: [] })).toBe(false);
        expect(hasMeaningfulInsights(empty)).toBe(false);
    });
});

describe('hasStockIntelligence', () => {
    it('returns true with opportunities only', () => {
        const a: AnalyticsData = { stock_intelligence: { opportunities: [{}], saturated_segments: [] } };
        expect(hasStockIntelligence(a)).toBe(true);
    });

    it('returns true with saturated segments only', () => {
        const a: AnalyticsData = { stock_intelligence: { opportunities: [], saturated_segments: [{}] } };
        expect(hasStockIntelligence(a)).toBe(true);
    });

    it('returns false when both arrays are empty', () => {
        const a: AnalyticsData = { stock_intelligence: { opportunities: [], saturated_segments: [] } };
        expect(hasStockIntelligence(a)).toBe(false);
        expect(hasStockIntelligence(empty)).toBe(false);
    });
});

describe('hasMarketingOverview', () => {
    it('returns true with views > 0', () => {
        const a: AnalyticsData = { marketing_performance: { views_last_7_days: 42 } };
        expect(hasMarketingOverview(a)).toBe(true);
    });

    it('returns true with spend > 0', () => {
        const a: AnalyticsData = { marketing_roi: { summary: { total_spend: 120.5 } } };
        expect(hasMarketingOverview(a)).toBe(true);
    });
});

describe('hasAdRanking', () => {
    it('returns true when cars_ranked_for_ads has entries', () => {
        const a: AnalyticsData = { ads_priority_ranking: { cars_ranked_for_ads: [{}] } };
        expect(hasAdRanking(a)).toBe(true);
    });
});

describe('hasPersonaData', () => {
    it('returns true when at least one persona has cars', () => {
        const a: AnalyticsData = { personas: [{ cars_count: 3 }, { cars_count: 0 }] };
        expect(hasPersonaData(a)).toBe(true);
    });

    it('returns false when all personas have cars_count zero', () => {
        const a: AnalyticsData = { personas: [{ cars_count: 0 }, { cars_count: 0 }] };
        expect(hasPersonaData(a)).toBe(false);
        expect(hasPersonaData(empty)).toBe(false);
    });
});

describe('visibleMarketingTabs', () => {
    it('returns only tabs that have data', () => {
        const a: AnalyticsData = {
            ads_priority_ranking: { cars_ranked_for_ads: [{}] },
            personas: [{ cars_count: 2 }],
        };
        const tabs = visibleMarketingTabs(a);
        expect(tabs).not.toContain('overview');
        expect(tabs).toContain('ranking');
        expect(tabs).toContain('personas');
    });

    it('returns empty array when no marketing data exists', () => {
        expect(visibleMarketingTabs(empty)).toEqual([]);
    });
});
