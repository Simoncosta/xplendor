import { createSlice } from "@reduxjs/toolkit";
import { carAiAnalyses, carRecalculate, refreshCarMetaAds, regenerateCarAnalysis } from "./thunk";

const initialState = {
    data: {
        analytics: [] as any[],
    },
    loading: {
        create: false,
        update: false,
        refreshMeta: false,
        regenerate: false,
    },
    error: {
        create: null as any,
        update: null as any,
        refreshMeta: null as any,
        regenerate: null as any,
    },
};

const CarSlice = createSlice({
    name: "car-ia-analises",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // SHOW
        builder
            .addCase(carAiAnalyses.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(carAiAnalyses.fulfilled, (state, action) => {
                state.loading.create = false;
                state.error.create = null;
                state.data.analytics = action.payload.data;
            })
            .addCase(carAiAnalyses.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        builder
            .addCase(carRecalculate.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(carRecalculate.fulfilled, (state, action) => {
                state.loading.update = false;
                state.error.update = null;
                state.data.analytics = action.payload.data;
            })
            .addCase(carRecalculate.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });

        builder
            .addCase(refreshCarMetaAds.pending, (state) => {
                state.loading.refreshMeta = true;
                state.error.refreshMeta = null;
            })
            .addCase(refreshCarMetaAds.fulfilled, (state, action) => {
                state.loading.refreshMeta = false;
                state.error.refreshMeta = null;
                state.data.analytics = action.payload.data;
            })
            .addCase(refreshCarMetaAds.rejected, (state, action) => {
                state.loading.refreshMeta = false;
                state.error.refreshMeta = action.payload || action.error;
            });

        builder
            .addCase(regenerateCarAnalysis.pending, (state) => {
                state.loading.regenerate = true;
                state.error.regenerate = null;
            })
            .addCase(regenerateCarAnalysis.fulfilled, (state, action) => {
                state.loading.regenerate = false;
                state.error.regenerate = null;
                state.data.analytics = action.payload.data;
            })
            .addCase(regenerateCarAnalysis.rejected, (state, action) => {
                state.loading.regenerate = false;
                state.error.regenerate = action.payload || action.error;
            });

    },
});

export default CarSlice.reducer;
