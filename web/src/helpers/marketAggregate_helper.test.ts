// Axios 1.x ships as ESM; Jest/CRA Babel can't transform node_modules ESM.
// Use a factory mock that replaces the module before any import is attempted.
jest.mock('axios', () => {
    const mock = {
        get: jest.fn(),
        post: jest.fn(),
        defaults: { baseURL: '', headers: { post: {} } },
        interceptors: { request: { use: jest.fn() }, response: { use: jest.fn() } },
    };
    return { __esModule: true, default: mock };
});

import axios from 'axios';
import { fetchMarketAggregate, refreshMarketAggregate } from './marketAggregate_helper';
import type { MarketAggregate, RefreshMarketAggregateResult } from '../types/api';

// The global axios interceptor in api_helper.ts does: return response.data ? response.data : response
// So after the interceptor, axios.get/post resolves with the JSON body directly.
// JSON body for GET:  { data: MarketAggregate | null }  → resolved value = { data: aggregate }
// JSON body for POST: { data: RefreshMarketAggregateResult, message: '...' } → resolved value = { data: result, ... }
// Helpers must read res.data (one level), not res.data.data (two levels).

const mockGet  = axios.get  as jest.Mock;
const mockPost = axios.post as jest.Mock;

const mockAggregate: MarketAggregate = {
    id: 42,
    status: 'success',
    confidence: 'high',
    comparables_count: 5,
    prices: { median: 18000, min: 16000, max: 20000, avg: 18200 },
    comparison: { car_price: 19000, difference_percent: 5.6, signal: 'slightly_high' },
    top_comparables: [],
    fallback_used: false,
    search_url: null,
    created_at: '2026-05-25T10:00:00Z',
    updated_at: '2026-05-25T10:00:00Z',
};

describe('fetchMarketAggregate', () => {
    beforeEach(() => jest.clearAllMocks());

    it('returns the aggregate when the server has data', async () => {
        mockGet.mockResolvedValue({ data: mockAggregate });

        const result = await fetchMarketAggregate(1, 42);

        expect(result).toEqual(mockAggregate);
    });

    it('returns null when the server has no aggregate', async () => {
        mockGet.mockResolvedValue({ data: null });

        const result = await fetchMarketAggregate(1, 42);

        expect(result).toBeNull();
    });

    it('passes aggregate_id as query param when provided', async () => {
        mockGet.mockResolvedValue({ data: null });

        await fetchMarketAggregate(1, 42, 27);

        expect(mockGet).toHaveBeenCalledWith(
            '/companies/1/cars/42/market-aggregate',
            { params: { aggregate_id: 27 } }
        );
    });

    it('omits params when aggregate_id is not provided', async () => {
        mockGet.mockResolvedValue({ data: null });

        await fetchMarketAggregate(1, 42);

        expect(mockGet).toHaveBeenCalledWith(
            '/companies/1/cars/42/market-aggregate',
            { params: undefined }
        );
    });
});

describe('refreshMarketAggregate', () => {
    beforeEach(() => jest.clearAllMocks());

    it('returns aggregate_id and status from the POST response', async () => {
        const mockResult: RefreshMarketAggregateResult = { aggregate_id: 27, status: 'pending' };
        mockPost.mockResolvedValue({ data: mockResult, message: 'Análise de mercado iniciada.' });

        const result = await refreshMarketAggregate(1, 42);

        expect(result).toEqual(mockResult);
        expect(result.aggregate_id).toBe(27);
        expect(result.status).toBe('pending');
    });
});
