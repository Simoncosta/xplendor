import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCarsPaginate as getCarsApi,
    showCar as showCarApi,
    createCar as createCarApi,
    updateCar as updateCarApi,
} from "../../helpers/laravel_helper";

export const getCarsPaginate = createAsyncThunk(
    "car/getCarsPaginate",
    async (
        params: { perPage: number; page: number; companyId: number; },
        { rejectWithValue }
    ) => {
        try {
            const response = await getCarsApi({
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

export const showCar = createAsyncThunk(
    "car/showCar",
    async ({ companyId, id }: { companyId: number, id: number }, { rejectWithValue }) => {
        try {
            return await showCarApi({ companyId, id });
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createCar = createAsyncThunk(
    "car/createCar",
    async ({ companyId, formData }: { companyId: number, formData: FormData }, { rejectWithValue }) => {
        try {
            return await createCarApi(companyId, formData);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateCar = createAsyncThunk(
    "car/updateCar",
    async ({ companyId, id, formData }: { companyId: number, id: number, formData: FormData }, { rejectWithValue }) => {
        try {
            return await updateCarApi(companyId, id, formData);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

