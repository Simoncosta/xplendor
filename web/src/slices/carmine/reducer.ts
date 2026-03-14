import { createSlice } from "@reduxjs/toolkit";
import { createCarmine, updateCarmine, showCarmine, syncCarmine } from "./thunk";
import { ICarmineApi } from "common/models/carmine-api.model";

const initialState = {
    carmine: [] as ICarmineApi[],
    loading: false,
    error: null as any,
};

const CarmineSlice = createSlice({
    name: "carmine",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // SHOW
        builder
            .addCase(showCarmine.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(showCarmine.fulfilled, (state, action) => {
                state.loading = false;
                state.carmine = action.payload.data;
            })
            .addCase(showCarmine.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });

        // CREATE
        builder
            .addCase(createCarmine.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(createCarmine.fulfilled, (state, action) => {
                state.loading = false;
                state.carmine = action.payload.data;
            })
            .addCase(createCarmine.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateCarmine.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(updateCarmine.fulfilled, (state, action) => {
                state.loading = false;
                state.carmine = action.payload.data;
            })
            .addCase(updateCarmine.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });

        // SYNC
        builder
            .addCase(syncCarmine.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(syncCarmine.fulfilled, (state, action) => {
                state.loading = false;
            })
            .addCase(syncCarmine.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default CarmineSlice.reducer;