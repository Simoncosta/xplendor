import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getExpenses as getExpensesApi,
    getExpensesSummary as getExpensesSummaryApi,
    createExpense as createExpenseApi,
    updateExpense as updateExpenseApi,
    deleteExpense as deleteExpenseApi,
} from "../../helpers/laravel_helper";
import { IExpensePayload } from "common/models/expense.model";

export const getExpenses = createAsyncThunk(
    "expense/getExpenses",
    async (params: { companyId: number } & Record<string, any>, { rejectWithValue }) => {
        try {
            const { companyId, ...rest } = params;
            return await getExpensesApi(companyId, rest);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const getExpensesSummary = createAsyncThunk(
    "expense/getExpensesSummary",
    async (params: { companyId: number } & Record<string, any>, { rejectWithValue }) => {
        try {
            const { companyId, ...rest } = params;
            return await getExpensesSummaryApi(companyId, rest);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createExpense = createAsyncThunk(
    "expense/createExpense",
    async (params: { companyId: number; data: IExpensePayload }, { rejectWithValue }) => {
        try {
            return await createExpenseApi(params.companyId, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateExpense = createAsyncThunk(
    "expense/updateExpense",
    async (params: { companyId: number; id: number; data: Partial<IExpensePayload> }, { rejectWithValue }) => {
        try {
            return await updateExpenseApi(params.companyId, params.id, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const deleteExpense = createAsyncThunk(
    "expense/deleteExpense",
    async (params: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            await deleteExpenseApi(params.companyId, params.id);
            return params.id;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
