import { createSlice } from "@reduxjs/toolkit";
import { getCarsPaginate, showCar, analyticsCar, getCarMarketing, generateCarMarketing } from "./thunk";

const initialState = {
    cars: [] as any[],
    meta: null as any,

    car: null as any | null,
    carAnalytics: null as any | null,
    carMarketing: [] as any[],

    loadingList: false,
    loadingShow: false,
    loadingAnalytics: false,
    loadingMarketing: false,

    errorList: null as any,
    errorShow: null as any,
    errorAnalytics: null as any,
    errorMarketing: null as any,
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

        // GET MARKETING
        builder
            .addCase(getCarMarketing.pending, (state) => {
                state.loadingMarketing = true;
                state.errorMarketing = null;
            })
            .addCase(getCarMarketing.fulfilled, (state, action) => {
                state.loadingMarketing = false;
                state.carMarketing = action.payload.data;
            })
            .addCase(getCarMarketing.rejected, (state, action) => {
                state.loadingMarketing = false;
                state.errorMarketing = action.payload || action.error;
            });

        // GENERATE MARKETING
        builder
            .addCase(generateCarMarketing.pending, (state) => {
                state.loadingMarketing = true;
                state.errorMarketing = null;
            })
            .addCase(generateCarMarketing.fulfilled, (state, action) => {
                state.loadingMarketing = false;
                if (action.payload.data) {
                    state.carMarketing = action.payload.data;
                }
            })
            .addCase(generateCarMarketing.rejected, (state, action) => {
                state.loadingMarketing = false;
                state.errorMarketing = action.payload || action.error;
            });
    },
});

export default CarSlice.reducer;
