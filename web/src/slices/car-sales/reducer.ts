import { createSlice } from "@reduxjs/toolkit";
import { closeCarSale } from "./thunk";

const initialState = {
    data: {
        sale: null as any | null,
    },
    loading: {
        create: false,
    },
    error: {
        create: null as any,
    },
};

const CarSaleSlice = createSlice({
    name: "carSale",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(closeCarSale.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(closeCarSale.fulfilled, (state, action) => {
                state.loading.create = false;
                state.error.create = null;
                state.data.sale = action.payload.data;
            })
            .addCase(closeCarSale.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });
    },
});

export default CarSaleSlice.reducer;
