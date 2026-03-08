import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    showCarmine as showCarmineApi,
    createCarmine as createCarmineApi,
    updateCarmine as updateCarmineApi,
    syncCarmine as syncCarmineApi,
} from "../../helpers/laravel_helper";
import { ICarmineApi } from "common/models/carmine-api.model";

export const showCarmine = createAsyncThunk(
    "carmine/showCarmine",
    async ({ companyId, id }: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            return await showCarmineApi({ companyId, id });
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createCarmine = createAsyncThunk(
    "carmine/createCarmine",
    async ({ companyId, data }: { companyId: number, data: ICarmineApi }, { rejectWithValue }) => {
        try {
            return await createCarmineApi(companyId, data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateCarmine = createAsyncThunk(
    "carmine/updateCarmine",
    async ({ companyId, id, data }: { companyId: number, id: number, data: ICarmineApi }, { rejectWithValue }) => {
        try {
            return await updateCarmineApi(companyId, id, data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const syncCarmine = createAsyncThunk(
    "carmine/syncCarmine",
    async ({ companyId }: { companyId: number }, { rejectWithValue }) => {
        try {
            return await syncCarmineApi(companyId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
