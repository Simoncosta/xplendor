import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCompaniesPaginate as getCompaniesApi,
    showCompany as showCompanyApi,
    createCompany as createCompanyApi,
    updateCompany as updateCompanyApi,
} from "../../helpers/laravel_helper";

export const getCompaniesPaginate = createAsyncThunk(
    "company/getCompaniesPaginate",
    async (
        params: { perPage: number; page: number },
        { rejectWithValue }
    ) => {
        try {
            const response = await getCompaniesApi(params);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const showCompany = createAsyncThunk(
    "company/showCompany",
    async (id: number, { rejectWithValue }) => {
        try {
            return await showCompanyApi({ id });
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createCompany = createAsyncThunk(
    "company/createCompany",
    async (formData: FormData, { rejectWithValue }) => {
        try {
            return await createCompanyApi(formData);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateCompany = createAsyncThunk(
    "company/updateCompany",
    async ({ id, formData }: { id: number, formData: FormData }, { rejectWithValue }) => {
        try {
            return await updateCompanyApi(id, formData);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

