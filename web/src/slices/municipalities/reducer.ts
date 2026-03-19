import { createSlice } from "@reduxjs/toolkit";
import { getMunicipalities } from "./thunk";

const initialState = {
    data: {
        municipalities: [] as [],
    },
    loading: {
        list: false,
    },
    error: {
        list: null as any,
    },
};

const MunicipalitySlice = createSlice({
    name: "district",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getMunicipalities.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getMunicipalities.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.municipalities = action.payload.data;
            })
            .addCase(getMunicipalities.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });
    },
});

export default MunicipalitySlice.reducer;
