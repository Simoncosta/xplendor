import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getParishes as getParishesApi,
} from "../../helpers/laravel_helper";

export const getParishes = createAsyncThunk(
    "parish/getParishes",
    async (municipality_id: number, { rejectWithValue }) => {
        try {
            return await getParishesApi(municipality_id);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);


