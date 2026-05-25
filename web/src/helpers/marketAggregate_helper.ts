import axios from "axios";
import type { MarketAggregate, RefreshMarketAggregateResult } from "../types/api";

const carMarketAggregateUrl = (companyId: number, carId: number): string =>
    `/companies/${companyId}/cars/${carId}/market-aggregate`;

export async function fetchMarketAggregate(
    companyId: number,
    carId: number,
    aggregateId?: number
): Promise<MarketAggregate | null> {
    const params = aggregateId !== undefined ? { aggregate_id: aggregateId } : undefined;
    // The global axios interceptor (api_helper.ts) returns response.data directly,
    // so res is the JSON body { data: MarketAggregate | null } and res.data is the aggregate.
    const res = await axios.get<MarketAggregate | null>(
        carMarketAggregateUrl(companyId, carId),
        { params }
    );
    return res.data ?? null;
}

export async function refreshMarketAggregate(
    companyId: number,
    carId: number
): Promise<RefreshMarketAggregateResult> {
    // Same interceptor: res is the JSON body { data: RefreshMarketAggregateResult, message: ... }
    // and res.data is { aggregate_id, status }.
    const res = await axios.post<RefreshMarketAggregateResult>(
        carMarketAggregateUrl(companyId, carId) + "/refresh"
    );
    return res.data;
}

export async function checkMarketLink(
    companyId: number,
    carId: number,
    url: string
): Promise<boolean> {
    // Interceptor returns JSON body directly; endpoint returns { available: boolean }.
    const res = await axios.get<{ available: boolean }>(
        carMarketAggregateUrl(companyId, carId) + "/check-link",
        { params: { url } }
    );
    return res.data?.available ?? false;
}
