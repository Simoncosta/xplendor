import { createSlice } from "@reduxjs/toolkit";
import { getDistricts } from "./thunk";

const initialState = {
    districts: [] as [],
    loading: false,
    error: null as any,
};

const DistrictSlice = createSlice({
    name: "district",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getDistricts.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(getDistricts.fulfilled, (state, action) => {
                state.loading = false;
                state.districts = action.payload.data;
            })
            .addCase(getDistricts.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default DistrictSlice.reducer;