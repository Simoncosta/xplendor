import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCarBrands as getCarBrandsApi,
} from "../../helpers/laravel_helper";

export const getCarBrands = createAsyncThunk(
    "car-brands/getCarBrands",
    async (
        _,
        { rejectWithValue }
    ) => {
        try {
            const response = await getCarBrandsApi();
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
