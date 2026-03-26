import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCarsPaginate as getCarsApi,
    showCar as showCarApi,
    createCar as createCarApi,
    updateCar as updateCarApi,
    analyticsCar as analyticsCarApi,
    generateCarMarketingApi,
    getCarMarketingApi,
} from "../../helpers/laravel_helper";

export const getCarsPaginate = createAsyncThunk(
    "car/getCarsPaginate",
    async (
        params: {
            perPage: number;
            page: number;
            companyId: number;
            status?: 'active' | 'sold' | 'draft' | 'available_soon';
            is_resume?: boolean;
            carBrandIds?: number[];
            carModelIds?: number[];
            mincost?: number;
            maxcost?: number;
            sort_by?: string;
            sort_direction?: 'asc' | 'desc';
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await getCarsApi({
                perPage: params.perPage,
                page: params.page,
                companyId: params.companyId,
                status: params.status,
                is_resume: params.is_resume,
                carBrandIds: params.carBrandIds,
                carModelIds: params.carModelIds,
                mincost: params.mincost,
                maxcost: params.maxcost,
                sort_by: params.sort_by,
                sort_direction: params.sort_direction,
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

export const getCarMarketing = createAsyncThunk(
    "car/getCarMarketing",
    async ({ companyId, id }: { companyId: number, id: number }, { rejectWithValue }) => {
        try {
            return await getCarMarketingApi(companyId, id);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const generateCarMarketing = createAsyncThunk(
    "car/generateCarMarketing",
    async ({ companyId, carId }: { companyId: number, carId?: number }, { rejectWithValue }) => {
        try {
            return await generateCarMarketingApi(companyId, carId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
