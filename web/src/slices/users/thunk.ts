import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getUsersPaginate as getUsersPaginateApi,
    showUser as showUserApi,
    createUser as createUserApi,
    updateUser as updateUserApi,
} from "../../helpers/laravel_helper";

export const getUsersPaginate = createAsyncThunk(
    "user/getUsersPaginate",
    async (
        params: {
            perPage: number;
            page: number;
            companyId: number;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await getUsersPaginateApi({
                perPage: params.perPage,
                page: params.page,
                companyId: params.companyId,
            });
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const showUser = createAsyncThunk(
    "user/showUser",
    async ({ companyId, id }: { companyId: number, id: number }, { rejectWithValue }) => {
        try {
            return await showUserApi({ companyId, id });
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createUser = createAsyncThunk(
    "user/createUser",
    async ({ companyId, formData }: { companyId: number, formData: FormData }, { rejectWithValue }) => {
        try {
            return await createUserApi(companyId, formData);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateUser = createAsyncThunk(
    "user/updateUser",
    async ({ companyId, id, formData }: { companyId: number, id: number, formData: FormData }, { rejectWithValue }) => {
        try {
            return await updateUserApi(companyId, id, formData);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
