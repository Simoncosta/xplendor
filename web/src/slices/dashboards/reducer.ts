import { createSlice } from "@reduxjs/toolkit";
import { getAnalyticsDashboard, getStockBreakdown, getSalesRevenue } from "./thunk";
import type { StockBreakdown, SalesRevenue } from "../../types/api";

const initialState = {
    data: {
        analytics: null as any,
        // Visões 1+2 (2026-06-25) — stock por marca + tipo.
        stockBreakdown: null as StockBreakdown | null,
        // Visão 3 (2026-06-25) — faturação (NÃO lucro) por período.
        salesRevenue: null as SalesRevenue | null,
    },
    loading: {
        list: false,
        stockBreakdown: false,
        salesRevenue: false,
    },
    error: {
        list: null as any,
        stockBreakdown: null as any,
        salesRevenue: null as any,
    },
};

const DashboardSlice = createSlice({
    name: "dashboard",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getAnalyticsDashboard.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getAnalyticsDashboard.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.analytics = action.payload.data;
            })
            .addCase(getAnalyticsDashboard.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            })
            // Stock breakdown (V1+V2)
            .addCase(getStockBreakdown.pending, (state) => {
                state.loading.stockBreakdown = true;
                state.error.stockBreakdown = null;
            })
            .addCase(getStockBreakdown.fulfilled, (state, action: any) => {
                state.loading.stockBreakdown = false;
                state.error.stockBreakdown = null;
                state.data.stockBreakdown = action.payload?.data ?? null;
            })
            .addCase(getStockBreakdown.rejected, (state, action) => {
                state.loading.stockBreakdown = false;
                state.error.stockBreakdown = action.payload || action.error;
            })
            // Sales revenue (V3)
            .addCase(getSalesRevenue.pending, (state) => {
                state.loading.salesRevenue = true;
                state.error.salesRevenue = null;
                // Limpa o valor anterior — não mostrar número velho durante carregamento.
                state.data.salesRevenue = null;
            })
            .addCase(getSalesRevenue.fulfilled, (state, action: any) => {
                state.loading.salesRevenue = false;
                state.error.salesRevenue = null;
                state.data.salesRevenue = action.payload?.data ?? null;
            })
            .addCase(getSalesRevenue.rejected, (state, action) => {
                state.loading.salesRevenue = false;
                state.error.salesRevenue = action.payload || action.error;
            });
    },
});

export default DashboardSlice.reducer;
