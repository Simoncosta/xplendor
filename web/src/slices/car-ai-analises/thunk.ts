import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    postCarAiAnalyses as postCarAiAnalysesApi,
    postCarRecalculate as postCarRecalculateApi,
    postCarMetaAdsRefresh as postCarMetaAdsRefreshApi,
    postCarAnalysisRegenerate as postCarAnalysisRegenerateApi,
} from "../../helpers/laravel_helper";

export const carAiAnalyses = createAsyncThunk(
    "car-ai/carAiAnalyses",
    async ({ companyId, carId }: { companyId: number, carId: number }, { rejectWithValue }) => {
        try {
            return await postCarAiAnalysesApi(companyId, carId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const carRecalculate = createAsyncThunk(
    "car-recalculate/carRecalculate",
    async ({ companyId, carId }: { companyId: number, carId: number }, { rejectWithValue }) => {
        try {
            return await postCarRecalculateApi(companyId, carId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const refreshCarMetaAds = createAsyncThunk(
    "car-ai/refreshCarMetaAds",
    async ({ companyId, carId }: { companyId: number, carId: number }, { rejectWithValue }) => {
        try {
            return await postCarMetaAdsRefreshApi(companyId, carId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const regenerateCarAnalysis = createAsyncThunk(
    "car-ai/regenerateCarAnalysis",
    async ({ companyId, carId }: { companyId: number, carId: number }, { rejectWithValue }) => {
        try {
            return await postCarAnalysisRegenerateApi(companyId, carId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
