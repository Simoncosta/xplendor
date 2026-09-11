import { createSlice } from "@reduxjs/toolkit";
import {
    getExpenses,
    getExpensesSummary,
    createExpense,
    updateExpense,
    deleteExpense,
} from "./thunk";
import { IExpense, IExpenseSummary } from "common/models/expense.model";

const initialState = {
    data: {
        expenses: [] as IExpense[],
        meta: null as any,
        summary: null as IExpenseSummary | null,
    },
    loading: {
        list: false,
        summary: false,
        create: false,
        update: false,
        remove: false,
    },
    error: {
        list: null as any,
        summary: null as any,
        create: null as any,
        update: null as any,
        remove: null as any,
    },
};

const ExpenseSlice = createSlice({
    name: "expense",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getExpenses.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getExpenses.fulfilled, (state, action: any) => {
                state.loading.list = false;
                state.data.expenses = action.payload.data?.data ?? [];
                state.data.meta = action.payload.data ?? null;
            })
            .addCase(getExpenses.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        builder
            .addCase(getExpensesSummary.pending, (state) => {
                state.loading.summary = true;
            })
            .addCase(getExpensesSummary.fulfilled, (state, action: any) => {
                state.loading.summary = false;
                state.data.summary = action.payload.data ?? null;
            })
            .addCase(getExpensesSummary.rejected, (state, action) => {
                state.loading.summary = false;
                state.error.summary = action.payload || action.error;
            });

        builder
            .addCase(createExpense.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createExpense.fulfilled, (state) => {
                state.loading.create = false;
            })
            .addCase(createExpense.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        builder
            .addCase(updateExpense.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateExpense.fulfilled, (state) => {
                state.loading.update = false;
            })
            .addCase(updateExpense.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });

        builder
            .addCase(deleteExpense.pending, (state) => {
                state.loading.remove = true;
                state.error.remove = null;
            })
            .addCase(deleteExpense.fulfilled, (state, action: any) => {
                state.loading.remove = false;
                state.data.expenses = state.data.expenses.filter((e) => e.id !== action.payload);
            })
            .addCase(deleteExpense.rejected, (state, action) => {
                state.loading.remove = false;
                state.error.remove = action.payload || action.error;
            });
    },
});

export default ExpenseSlice.reducer;
