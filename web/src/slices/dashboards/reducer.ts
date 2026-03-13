import { createSlice } from "@reduxjs/toolkit";
import { getAnalyticsDashboard } from "./thunk";

const initialState = {
    analytics: [] as any[],
    loading: false,
    error: null as any,
};

const DashboardSlice = createSlice({
    name: "dashboard",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getAnalyticsDashboard.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(getAnalyticsDashboard.fulfilled, (state, action) => {
                state.loading = false;
                state.analytics = action.payload.data;
            })
            .addCase(getAnalyticsDashboard.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default DashboardSlice.reducer;