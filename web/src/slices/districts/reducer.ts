import { createSlice } from "@reduxjs/toolkit";
import { getDistricts } from "./thunk";

const initialState = {
    data: {
        districts: [] as [],
    },
    loading: {
        list: false,
    },
    error: {
        list: null as any,
    },
};

const DistrictSlice = createSlice({
    name: "district",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getDistricts.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getDistricts.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.districts = action.payload.data;
            })
            .addCase(getDistricts.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });
    },
});

export default DistrictSlice.reducer;
