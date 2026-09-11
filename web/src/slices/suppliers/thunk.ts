import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getSuppliers as getSuppliersApi,
    showSupplier as showSupplierApi,
    createSupplier as createSupplierApi,
    updateSupplier as updateSupplierApi,
    deleteSupplier as deleteSupplierApi,
} from "../../helpers/laravel_helper";
import { ISupplierPayload } from "common/models/supplier.model";

export const getSuppliers = createAsyncThunk(
    "supplier/getSuppliers",
    async (
        params: { companyId: number; perPage?: number; page?: number; search?: string },
        { rejectWithValue }
    ) => {
        try {
            const { companyId, ...rest } = params;
            return await getSuppliersApi(companyId, rest);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const showSupplier = createAsyncThunk(
    "supplier/showSupplier",
    async (params: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            return await showSupplierApi(params.companyId, params.id);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createSupplier = createAsyncThunk(
    "supplier/createSupplier",
    async (params: { companyId: number; data: ISupplierPayload }, { rejectWithValue }) => {
        try {
            return await createSupplierApi(params.companyId, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateSupplier = createAsyncThunk(
    "supplier/updateSupplier",
    async (params: { companyId: number; id: number; data: Partial<ISupplierPayload> }, { rejectWithValue }) => {
        try {
            return await updateSupplierApi(params.companyId, params.id, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const deleteSupplier = createAsyncThunk(
    "supplier/deleteSupplier",
    async (params: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            await deleteSupplierApi(params.companyId, params.id);
            return params.id;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
