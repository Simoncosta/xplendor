import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getDocumentTemplates as getApi,
    createDocumentTemplate as createApi,
    updateDocumentTemplate as updateApi,
    deleteDocumentTemplate as deleteApi,
} from "../../helpers/laravel_helper";

export const getDocumentTemplates = createAsyncThunk(
    "documentTemplates/getAll",
    async (params: { companyId: number }, { rejectWithValue }) => {
        try {
            return await getApi(params.companyId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createDocumentTemplate = createAsyncThunk(
    "documentTemplates/create",
    async (params: { companyId: number; data: FormData }, { rejectWithValue }) => {
        try {
            return await createApi(params.companyId, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateDocumentTemplate = createAsyncThunk(
    "documentTemplates/update",
    async (params: { companyId: number; id: number; data: any }, { rejectWithValue }) => {
        try {
            return await updateApi(params.companyId, params.id, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const deleteDocumentTemplate = createAsyncThunk(
    "documentTemplates/delete",
    async (params: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            await deleteApi(params.companyId, params.id);
            return params.id;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
