import { createSlice } from "@reduxjs/toolkit";
import { carAiAnalyses } from "./thunk";

const initialState = {
    analytics: [] as any[],
    loading: false,
    error: null as any,
};

const CarSlice = createSlice({
    name: "car-ia-analises",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // SHOW
        builder
            .addCase(carAiAnalyses.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(carAiAnalyses.fulfilled, (state, action) => {
                state.loading = false;
                state.analytics = action.payload.data;
            })
            .addCase(carAiAnalyses.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });

    },
});

export default CarSlice.reducer;