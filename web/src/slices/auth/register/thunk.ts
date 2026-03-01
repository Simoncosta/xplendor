import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getUserByInvite as getUserByInviteApi,
} from "../../../helpers/laravel_helper";

export const getUserByInvite = createAsyncThunk(
    "userInvite/getUserByInvite",
    async (
        token: string,
        { rejectWithValue }
    ) => {
        try {
            const response = await getUserByInviteApi(token);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);