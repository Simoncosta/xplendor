import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getSupportTickets as getApi,
    createSupportTicket as createApi,
} from "../../helpers/laravel_helper";

export const getSupportTickets = createAsyncThunk(
    "supportTickets/getAll",
    async (params: { companyId: number }, { rejectWithValue }) => {
        try {
            return await getApi(params.companyId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createSupportTicket = createAsyncThunk(
    "supportTickets/create",
    async (params: { companyId: number; data: FormData }, { rejectWithValue }) => {
        try {
            return await createApi(params.companyId, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
