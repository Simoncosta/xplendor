import { createSlice } from "@reduxjs/toolkit";
import {
    getSuppliers,
    showSupplier,
    createSupplier,
    updateSupplier,
    deleteSupplier,
} from "./thunk";
import { ISupplier } from "common/models/supplier.model";

const initialState = {
    data: {
        suppliers: [] as ISupplier[],
        meta: null as any,
        supplier: null as ISupplier | null,
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

const SupplierSlice = createSlice({
    name: "supplier",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getSuppliers.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getSuppliers.fulfilled, (state, action: any) => {
                state.loading.list = false;
                state.error.list = null;
                // Paginado: payload.data = paginator, payload.data.data = array.
                state.data.suppliers = action.payload.data?.data ?? [];
                state.data.meta = action.payload.data ?? null;
            })
            .addCase(getSuppliers.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showSupplier.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(showSupplier.fulfilled, (state, action: any) => {
                state.loading.show = false;
                state.data.supplier = action.payload.data;
            })
            .addCase(showSupplier.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });

        // CREATE
        builder
            .addCase(createSupplier.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createSupplier.fulfilled, (state, action: any) => {
                state.loading.create = false;
                state.data.supplier = action.payload.data;
            })
            .addCase(createSupplier.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateSupplier.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateSupplier.fulfilled, (state, action: any) => {
                state.loading.update = false;
                state.data.supplier = action.payload.data;
            })
            .addCase(updateSupplier.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });

        // DELETE
        builder
            .addCase(deleteSupplier.pending, (state) => {
                state.loading.remove = true;
                state.error.remove = null;
            })
            .addCase(deleteSupplier.fulfilled, (state, action: any) => {
                state.loading.remove = false;
                state.data.suppliers = state.data.suppliers.filter((s) => s.id !== action.payload);
            })
            .addCase(deleteSupplier.rejected, (state, action) => {
                state.loading.remove = false;
                state.error.remove = action.payload || action.error;
            });
    },
});

export default SupplierSlice.reducer;
