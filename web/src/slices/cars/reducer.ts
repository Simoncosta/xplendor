import { createSlice } from "@reduxjs/toolkit";
import { getCarsPaginate, showCar, createCar, updateCar, analyticsCar, getCarMarketing, generateCarMarketing } from "./thunk";

const initialState = {
    data: {
        cars: [] as any[],
        meta: null as any,
        car: null as any | null,
        carAnalytics: null as any | null,
        carMarketing: [] as any[],
    },
    loading: {
        list: false,
        show: false,
        create: false,
        update: false,
        analytics: false,
        marketing: false,
        generate: false,
    },
    error: {
        list: null as any,
        show: null as any,
        create: null as any,
        update: null as any,
        analytics: null as any,
        marketing: null as any,
        generate: null as any,
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

        // GET MARKETING
        builder
            .addCase(getCarMarketing.pending, (state) => {
                state.loading.marketing = true;
                state.error.marketing = null;
            })
            .addCase(getCarMarketing.fulfilled, (state, action) => {
                state.loading.marketing = false;
                state.error.marketing = null;
                state.data.carMarketing = action.payload.data;
            })
            .addCase(getCarMarketing.rejected, (state, action) => {
                state.loading.marketing = false;
                state.error.marketing = action.payload || action.error;
            });

        // GENERATE MARKETING
        builder
            .addCase(generateCarMarketing.pending, (state) => {
                state.loading.generate = true;
                state.error.generate = null;
            })
            .addCase(generateCarMarketing.fulfilled, (state, action) => {
                state.loading.generate = false;
                state.error.generate = null;
                if (action.payload.data) {
                    state.data.carMarketing = action.payload.data;
                }
            })
            .addCase(generateCarMarketing.rejected, (state, action) => {
                state.loading.generate = false;
                state.error.generate = action.payload || action.error;
            });
    },
});

export default CarSlice.reducer;
