import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getAnalyticsDashboard as getAnalyticsDashboardApi,
    getDashboardStockBreakdown as getDashboardStockBreakdownApi,
    getDashboardSalesRevenue as getDashboardSalesRevenueApi,
} from "../../helpers/laravel_helper";
import type { SalesRevenueGranularity } from "../../types/api";

export const getAnalyticsDashboard = createAsyncThunk(
    "dashboard/getAnalyticsDashboard",
    async (
        params: {
            companyId: number;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await getAnalyticsDashboardApi(params.companyId);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

// Visões 1+2 (2026-06-25) — stock por marca + tipo.
export const getStockBreakdown = createAsyncThunk(
    "dashboard/getStockBreakdown",
    async (
        params: { companyId: number },
        { rejectWithValue },
    ) => {
        try {
            const response = await getDashboardStockBreakdownApi(params.companyId);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    },
);

// Visão 3 (2026-06-25) — FATURAÇÃO (NÃO é lucro) por período.
export const getSalesRevenue = createAsyncThunk(
    "dashboard/getSalesRevenue",
    async (
        params: { companyId: number; from: string; to: string; granularity?: SalesRevenueGranularity },
        { rejectWithValue },
    ) => {
        try {
            const response = await getDashboardSalesRevenueApi(params.companyId, {
                from: params.from,
                to: params.to,
                granularity: params.granularity,
            });
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    },
);
