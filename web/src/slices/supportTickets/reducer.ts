import { createSlice } from "@reduxjs/toolkit";
import { getSupportTickets, createSupportTicket } from "./thunk";
import { ISupportTicket } from "common/models/supportTicket.model";

const initialState = {
    data: {
        tickets: [] as ISupportTicket[],
    },
    loading: {
        list: false,
        create: false,
    },
    error: {
        list: null as any,
        create: null as any,
    },
};

const slice = createSlice({
    name: "supportTickets",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getSupportTickets.pending, (state) => { state.loading.list = true; state.error.list = null; })
            .addCase(getSupportTickets.fulfilled, (state, action: any) => {
                state.loading.list = false;
                state.data.tickets = action.payload?.data ?? [];
            })
            .addCase(getSupportTickets.rejected, (state, action) => { state.loading.list = false; state.error.list = action.payload || action.error; });

        builder
            .addCase(createSupportTicket.pending, (state) => { state.loading.create = true; state.error.create = null; })
            .addCase(createSupportTicket.fulfilled, (state, action: any) => {
                state.loading.create = false;
                if (action.payload?.data) state.data.tickets = [action.payload.data, ...state.data.tickets];
            })
            .addCase(createSupportTicket.rejected, (state, action) => { state.loading.create = false; state.error.create = action.payload || action.error; });
    },
});

export default slice.reducer;
