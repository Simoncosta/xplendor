import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getDistricts as getDistrictsApi,
} from "../../helpers/laravel_helper";

export const getDistricts = createAsyncThunk(
    "district/getDistricts",
    async (_, { rejectWithValue }) => {
        try {
            return await getDistrictsApi();
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);


