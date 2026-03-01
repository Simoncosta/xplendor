import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getMunicipalities as getMunicipalitiesApi,
} from "../../helpers/laravel_helper";

export const getMunicipalities = createAsyncThunk(
    "municipality/getMunicipalities",
    async (districtId: number, { rejectWithValue }) => {
        try {
            return await getMunicipalitiesApi(districtId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);


