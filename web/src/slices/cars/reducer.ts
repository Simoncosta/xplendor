import { createSlice } from "@reduxjs/toolkit";
import { getCarsPaginate, showCar, analyticsCar } from "./thunk";

const initialState = {
    cars: [] as any[],
    meta: null as any,

    car: null as any | null,
    carAnalytics: null as any | null,

    loadingList: false,
    loadingShow: false,
    loadingAnalytics: false,

    errorList: null as any,
    errorShow: null as any,
    errorAnalytics: null as any,
};

const CarSlice = createSlice({
    name: "company",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getCarsPaginate.pending, (state) => {
                state.loadingList = true;
                state.errorList = null;
            })
            .addCase(getCarsPaginate.fulfilled, (state, action) => {
                state.loadingList = false;
                state.cars = action.payload.data.data;
                state.meta = action.payload.data;
            })
            .addCase(getCarsPaginate.rejected, (state, action) => {
                state.loadingList = false;
                state.errorList = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showCar.pending, (state) => {
                state.loadingShow = true;
                state.errorShow = null;
            })
            .addCase(showCar.fulfilled, (state, action) => {
                state.loadingShow = false;
                state.car = action.payload.data;
            })
            .addCase(showCar.rejected, (state, action) => {
                state.loadingShow = false;
                state.errorShow = action.payload || action.error;
            });

        // ANALYTICS
        builder
            .addCase(analyticsCar.pending, (state) => {
                state.loadingAnalytics = true;
                state.errorAnalytics = null;
            })
            .addCase(analyticsCar.fulfilled, (state, action) => {
                state.loadingAnalytics = false;
                state.carAnalytics = action.payload.data;
            })
            .addCase(analyticsCar.rejected, (state, action) => {
                state.loadingAnalytics = false;
                state.errorAnalytics = action.payload || action.error;
            });
    },
});

export default CarSlice.reducer;