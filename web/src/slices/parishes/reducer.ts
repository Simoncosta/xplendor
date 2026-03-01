import { createSlice } from "@reduxjs/toolkit";
import { getParishes } from "./thunk";

const initialState = {
    parishes: [] as [],
    loading: false,
    error: null as any,
};

const ParishSlice = createSlice({
    name: "parish",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getParishes.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(getParishes.fulfilled, (state, action) => {
                state.loading = false;
                state.parishes = action.payload.data;
            })
            .addCase(getParishes.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default ParishSlice.reducer;