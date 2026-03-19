import { createSlice } from "@reduxjs/toolkit";
import { getCarBrands } from "./thunk";

const initialState = {
    data: {
        brands: [] as any[],
    },
    loading: {
        list: false,
    },
    error: {
        list: null as any,
    },
};

const CarBrandsSlice = createSlice({
    name: "car-brands",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getCarBrands.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getCarBrands.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.brands = action.payload.data;
            })
            .addCase(getCarBrands.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });
    },
});

export default CarBrandsSlice.reducer;
