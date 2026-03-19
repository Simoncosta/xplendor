import { createSlice } from "@reduxjs/toolkit";
import { getLeadsPaginate } from "./thunk";

const initialState = {
    data: {
        leads: [] as any[],
        meta: null as any,
        lead: null as any | null,
    },
    loading: {
        list: false,
    },
    error: {
        list: null as any,
    },
};

const LeadSlice = createSlice({
    name: "blog",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getLeadsPaginate.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getLeadsPaginate.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.leads = action.payload.data.data;
                state.data.meta = action.payload.data;
            })
            .addCase(getLeadsPaginate.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });
    },
});

export default LeadSlice.reducer;
