import { createSlice } from "@reduxjs/toolkit";
import { getAnalyticsDashboard } from "./thunk";

const initialState = {
    data: {
        analytics: [] as any[],
    },
    loading: {
        list: false,
    },
    error: {
        list: null as any,
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
            });
    },
});

export default DashboardSlice.reducer;
