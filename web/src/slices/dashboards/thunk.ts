import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getAnalyticsDashboard as getAnalyticsDashboardApi,
} from "../../helpers/laravel_helper";

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
