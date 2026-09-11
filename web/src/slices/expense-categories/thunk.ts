import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getExpenseCategories as getExpenseCategoriesApi,
    createExpenseCategory as createExpenseCategoryApi,
    updateExpenseCategory as updateExpenseCategoryApi,
    deleteExpenseCategory as deleteExpenseCategoryApi,
    importSuggestedExpenseCategories as importSuggestedApi,
} from "../../helpers/laravel_helper";
import { IExpenseCategoryPayload } from "common/models/expense-category.model";

export const getExpenseCategories = createAsyncThunk(
    "expenseCategory/getAll",
    async (params: { companyId: number; only_active?: number }, { rejectWithValue }) => {
        try {
            const { companyId, ...rest } = params;
            return await getExpenseCategoriesApi(companyId, rest);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createExpenseCategory = createAsyncThunk(
    "expenseCategory/create",
    async (params: { companyId: number; data: IExpenseCategoryPayload }, { rejectWithValue }) => {
        try {
            return await createExpenseCategoryApi(params.companyId, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateExpenseCategory = createAsyncThunk(
    "expenseCategory/update",
    async (params: { companyId: number; id: number; data: Partial<IExpenseCategoryPayload> }, { rejectWithValue }) => {
        try {
            return await updateExpenseCategoryApi(params.companyId, params.id, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const deleteExpenseCategory = createAsyncThunk(
    "expenseCategory/delete",
    async (params: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            await deleteExpenseCategoryApi(params.companyId, params.id);
            return params.id;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const importSuggestedExpenseCategories = createAsyncThunk(
    "expenseCategory/importSuggested",
    async (params: { companyId: number }, { rejectWithValue }) => {
        try {
            return await importSuggestedApi(params.companyId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
