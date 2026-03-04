import { createSlice } from "@reduxjs/toolkit";
import { getCarBrands } from "./thunk";

const initialState = {
    brands: [] as any[],
    loading: false,
    error: null as any,
};

const CarBrandsSlice = createSlice({
    name: "car-brands",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getCarBrands.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(getCarBrands.fulfilled, (state, action) => {
                state.loading = false;
                state.brands = action.payload.data;
            })
            .addCase(getCarBrands.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default CarBrandsSlice.reducer;