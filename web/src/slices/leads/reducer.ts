import { createSlice } from "@reduxjs/toolkit";
import { getLeadsPaginate } from "./thunk";

const initialState = {
    leads: [] as any[],
    meta: null as any,

    lead: null as any | null,

    loadingList: false,

    errorList: null as any,
};

const LeadSlice = createSlice({
    name: "blog",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getLeadsPaginate.pending, (state) => {
                state.loadingList = true;
                state.errorList = null;
            })
            .addCase(getLeadsPaginate.fulfilled, (state, action) => {
                state.loadingList = false;
                state.leads = action.payload.data.data;
                state.meta = action.payload.data;
            })
            .addCase(getLeadsPaginate.rejected, (state, action) => {
                state.loadingList = false;
                state.errorList = action.payload || action.error;
            });
    },
});

export default LeadSlice.reducer;