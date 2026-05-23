import { createSlice } from "@reduxjs/toolkit";
import { getCarsPaginate, showCar, createCar, updateCar, analyticsCar } from "./thunk";

const initialState = {
    data: {
        cars: [] as any[],
        meta: null as any,
        car: null as any | null,
        carAnalytics: null as any | null,
    },
    loading: {
        list: false,
        show: false,
        create: false,
        update: false,
        analytics: false,
    },
    error: {
        list: null as any,
        show: null as any,
        create: null as any,
        update: null as any,
        analytics: null as any,
    },
};

const CarSlice = createSlice({
    name: "company",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getCarsPaginate.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getCarsPaginate.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.cars = action.payload.data.data;
                state.data.meta = action.payload.data;
            })
            .addCase(getCarsPaginate.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showCar.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(showCar.fulfilled, (state, action) => {
                state.loading.show = false;
                state.error.show = null;
                state.data.car = action.payload.data;
            })
            .addCase(showCar.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });

        // CREATE
        builder
            .addCase(createCar.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createCar.fulfilled, (state, action) => {
                state.loading.create = false;
                state.error.create = null;
                state.data.car = action.payload.data;
            })
            .addCase(createCar.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateCar.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateCar.fulfilled, (state, action) => {
                state.loading.update = false;
                state.error.update = null;
                state.data.car = action.payload.data;
            })
            .addCase(updateCar.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });

        // ANALYTICS
        builder
            .addCase(analyticsCar.pending, (state) => {
                state.loading.analytics = true;
                state.error.analytics = null;
            })
            .addCase(analyticsCar.fulfilled, (state, action) => {
                state.loading.analytics = false;
                state.error.analytics = null;
                state.data.carAnalytics = action.payload.data;
            })
            .addCase(analyticsCar.rejected, (state, action) => {
                state.loading.analytics = false;
                state.error.analytics = action.payload || action.error;
            });

    },
});

export default CarSlice.reducer;
