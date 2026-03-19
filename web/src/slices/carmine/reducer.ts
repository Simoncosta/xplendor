import { createSlice } from "@reduxjs/toolkit";
import { createCarmine, updateCarmine, showCarmine, syncCarmine } from "./thunk";
import { ICarmineApi } from "common/models/carmine-api.model";

const initialState = {
    data: {
        carmine: [] as ICarmineApi[],
    },
    loading: {
        show: false,
        create: false,
        update: false,
        sync: false,
    },
    error: {
        show: null as any,
        create: null as any,
        update: null as any,
        sync: null as any,
    },
};

const CarmineSlice = createSlice({
    name: "carmine",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // SHOW
        builder
            .addCase(showCarmine.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(showCarmine.fulfilled, (state, action) => {
                state.loading.show = false;
                state.error.show = null;
                state.data.carmine = action.payload.data;
            })
            .addCase(showCarmine.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });

        // CREATE
        builder
            .addCase(createCarmine.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createCarmine.fulfilled, (state, action) => {
                state.loading.create = false;
                state.error.create = null;
                state.data.carmine = action.payload.data;
            })
            .addCase(createCarmine.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateCarmine.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateCarmine.fulfilled, (state, action) => {
                state.loading.update = false;
                state.error.update = null;
                state.data.carmine = action.payload.data;
            })
            .addCase(updateCarmine.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });

        // SYNC
        builder
            .addCase(syncCarmine.pending, (state) => {
                state.loading.sync = true;
                state.error.sync = null;
            })
            .addCase(syncCarmine.fulfilled, (state) => {
                state.loading.sync = false;
                state.error.sync = null;
            })
            .addCase(syncCarmine.rejected, (state, action) => {
                state.loading.sync = false;
                state.error.sync = action.payload || action.error;
            });
    },
});

export default CarmineSlice.reducer;
