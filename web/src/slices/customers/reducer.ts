import { createSlice } from "@reduxjs/toolkit";
import { getCustomers, showCustomer, createCustomer, updateCustomer, deleteCustomer } from "./thunk";
import { ICustomer } from "common/models/customer.model";

const initialState = {
    data: {
        customers: [] as ICustomer[],
        meta: null as any,
        customer: null as ICustomer | null,
    },
    loading: {
        list: false,
        show: false,
        create: false,
        update: false,
        remove: false,
    },
    error: {
        list: null as any,
        show: null as any,
        create: null as any,
        update: null as any,
        remove: null as any,
    },
};

const CustomerSlice = createSlice({
    name: "customer",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getCustomers.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getCustomers.fulfilled, (state, action: any) => {
                state.loading.list = false;
                state.data.customers = action.payload.data?.data ?? action.payload.data ?? [];
                state.data.meta = action.payload.data ?? null;
            })
            .addCase(getCustomers.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        builder
            .addCase(showCustomer.pending, (state) => { state.loading.show = true; state.error.show = null; })
            .addCase(showCustomer.fulfilled, (state, action: any) => { state.loading.show = false; state.data.customer = action.payload.data; })
            .addCase(showCustomer.rejected, (state, action) => { state.loading.show = false; state.error.show = action.payload || action.error; });

        builder
            .addCase(createCustomer.pending, (state) => { state.loading.create = true; state.error.create = null; })
            .addCase(createCustomer.fulfilled, (state, action: any) => { state.loading.create = false; state.data.customer = action.payload.data; })
            .addCase(createCustomer.rejected, (state, action) => { state.loading.create = false; state.error.create = action.payload || action.error; });

        builder
            .addCase(updateCustomer.pending, (state) => { state.loading.update = true; state.error.update = null; })
            .addCase(updateCustomer.fulfilled, (state, action: any) => { state.loading.update = false; state.data.customer = action.payload.data; })
            .addCase(updateCustomer.rejected, (state, action) => { state.loading.update = false; state.error.update = action.payload || action.error; });

        builder
            .addCase(deleteCustomer.pending, (state) => { state.loading.remove = true; state.error.remove = null; })
            .addCase(deleteCustomer.fulfilled, (state, action: any) => {
                state.loading.remove = false;
                state.data.customers = state.data.customers.filter((c) => c.id !== action.payload);
            })
            .addCase(deleteCustomer.rejected, (state, action) => { state.loading.remove = false; state.error.remove = action.payload || action.error; });
    },
});

export default CustomerSlice.reducer;
