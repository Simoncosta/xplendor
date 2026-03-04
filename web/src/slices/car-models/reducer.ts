import { createSlice } from "@reduxjs/toolkit";
import { getCarModels } from "./thunk";

const initialState = {
    models: [] as any[],
    loading: false,
    error: null as any,
};

const CarModelsSlice = createSlice({
    name: "car-models",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getCarModels.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(getCarModels.fulfilled, (state, action) => {
                state.loading = false;
                state.models = action.payload.data;
            })
            .addCase(getCarModels.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default CarModelsSlice.reducer;