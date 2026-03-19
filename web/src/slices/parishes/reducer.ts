import { createSlice } from "@reduxjs/toolkit";
import { getParishes } from "./thunk";

const initialState = {
    data: {
        parishes: [] as [],
    },
    loading: {
        list: false,
    },
    error: {
        list: null as any,
    },
};

const ParishSlice = createSlice({
    name: "parish",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getParishes.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getParishes.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.parishes = action.payload.data;
            })
            .addCase(getParishes.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });
    },
});

export default ParishSlice.reducer;
