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
    const res = await axios.get<{ data: MarketAggregate | null }>(
        carMarketAggregateUrl(companyId, carId),
        { params }
    );
    return res.data.data ?? null;
}

export async function refreshMarketAggregate(
    companyId: number,
    carId: number
): Promise<RefreshMarketAggregateResult> {
    const res = await axios.post<{ data: RefreshMarketAggregateResult }>(
        carMarketAggregateUrl(companyId, carId) + "/refresh"
    );
    return res.data.data;
}
