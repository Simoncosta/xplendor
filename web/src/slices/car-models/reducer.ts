import { createSlice } from "@reduxjs/toolkit";
import { getCarModels } from "./thunk";

const initialState = {
    data: {
        models: [] as any[],
    },
    loading: {
        list: false,
    },
    error: {
        list: null as any,
    },
};

const CarModelsSlice = createSlice({
    name: "car-models",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getCarModels.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getCarModels.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.models = action.payload.data;
            })
            .addCase(getCarModels.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });
    },
});

export default CarModelsSlice.reducer;
