import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCarBrands as getCarBrandsApi,
} from "../../helpers/laravel_helper";

export const getCarBrands = createAsyncThunk(
    "car-brands/getCarBrands",
    async (
        vehicleType: string | undefined = undefined,
        { rejectWithValue }
    ) => {
        try {
            const response = await getCarBrandsApi(vehicleType);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
