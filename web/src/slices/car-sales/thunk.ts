import { createAsyncThunk } from "@reduxjs/toolkit";
import { closeCarSale as closeCarSaleApi } from "../../helpers/laravel_helper";

export const closeCarSale = createAsyncThunk(
    "carSale/closeCarSale",
    async ({ companyId, carId, formData }: { companyId: number; carId: number; formData: FormData }, { rejectWithValue }) => {
        try {
            return await closeCarSaleApi(companyId, carId, formData);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
