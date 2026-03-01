import { createSlice } from "@reduxjs/toolkit";
import { getMunicipalities } from "./thunk";

const initialState = {
    municipalities: [] as [],
    loading: false,
    error: null as any,
};

const MunicipalitySlice = createSlice({
    name: "district",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getMunicipalities.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(getMunicipalities.fulfilled, (state, action) => {
                state.loading = false;
                state.municipalities = action.payload.data;
            })
            .addCase(getMunicipalities.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default MunicipalitySlice.reducer;