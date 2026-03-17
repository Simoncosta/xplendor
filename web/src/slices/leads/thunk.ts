import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getLeads as getLeadsApi,
} from "../../helpers/laravel_helper";

export const getLeadsPaginate = createAsyncThunk(
    "leads/getLeadsPaginate",
    async (
        params: {
            perPage: number;
            page: number;
            companyId: number;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await getLeadsApi({
                perPage: params.perPage,
                page: params.page,
                companyId: params.companyId
            });
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
