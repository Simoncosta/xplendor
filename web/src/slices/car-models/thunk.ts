import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCarModels as getCarModelsApi,
} from "../../helpers/laravel_helper";

export const getCarModels = createAsyncThunk(
    "car-models/getCarModels",
    async (
        brandId: number,
        { rejectWithValue }
    ) => {
        try {
            const response = await getCarModelsApi(brandId);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
