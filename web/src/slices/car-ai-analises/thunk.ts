import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    postCarAiAnalyses as postCarAiAnalysesApi,
    postCarRecalculate as postCarRecalculateApi,
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
