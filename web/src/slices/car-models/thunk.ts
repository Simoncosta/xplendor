import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCarModels as getCarModelsApi,
} from "../../helpers/laravel_helper";

export const getCarModels = createAsyncThunk(
    "car-models/getCarModels",
    async (
        params: number | number[] | { brand_id?: number | number[]; vehicle_type?: string; car_brand_id?: number | number[]; },
        { rejectWithValue }
    ) => {
        try {
            const response = await getCarModelsApi(params);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
