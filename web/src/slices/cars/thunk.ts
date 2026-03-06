import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCarsPaginate as getCarsApi,
    showCar as showCarApi,
    createCar as createCarApi,
    updateCar as updateCarApi,
    analyticsCar as analyticsCarApi,
} from "../../helpers/laravel_helper";

export const getCarsPaginate = createAsyncThunk(
    "car/getCarsPaginate",
    async (
        params: {
            perPage: number;
            page: number;
            companyId: number;
            status?: 'active' | 'sold' | 'draft';
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await getCarsApi({
                perPage: params.perPage,
                page: params.page,
                companyId: params.companyId,
                status: params.status,
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

export const analyticsCar = createAsyncThunk(
    "car/analyticsCar",
    async ({ companyId, id }: { companyId: number, id: number }, { rejectWithValue }) => {
        try {
            return await analyticsCarApi(companyId, id);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

