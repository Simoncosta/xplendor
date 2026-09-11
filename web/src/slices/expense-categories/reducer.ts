import { createSlice } from "@reduxjs/toolkit";
import {
    getExpenseCategories,
    createExpenseCategory,
    updateExpenseCategory,
    deleteExpenseCategory,
    importSuggestedExpenseCategories,
} from "./thunk";
import { IExpenseCategory } from "common/models/expense-category.model";

const initialState = {
    data: {
        categories: [] as IExpenseCategory[],
    },
    loading: {
        list: false,
        create: false,
        update: false,
        remove: false,
        import: false,
    },
    error: {
        list: null as any,
        create: null as any,
        update: null as any,
        remove: null as any,
        import: null as any,
    },
};

const ExpenseCategorySlice = createSlice({
    name: "expenseCategory",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getExpenseCategories.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getExpenseCategories.fulfilled, (state, action: any) => {
                state.loading.list = false;
                state.data.categories = action.payload.data ?? [];
            })
            .addCase(getExpenseCategories.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        // CREATE
        builder
            .addCase(createExpenseCategory.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createExpenseCategory.fulfilled, (state) => {
                state.loading.create = false;
            })
            .addCase(createExpenseCategory.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateExpenseCategory.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateExpenseCategory.fulfilled, (state) => {
                state.loading.update = false;
            })
            .addCase(updateExpenseCategory.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });

        // DELETE
        builder
            .addCase(deleteExpenseCategory.pending, (state) => {
                state.loading.remove = true;
                state.error.remove = null;
            })
            .addCase(deleteExpenseCategory.fulfilled, (state, action: any) => {
                state.loading.remove = false;
                state.data.categories = state.data.categories.filter((c) => c.id !== action.payload);
            })
            .addCase(deleteExpenseCategory.rejected, (state, action) => {
                state.loading.remove = false;
                state.error.remove = action.payload || action.error;
            });

        // IMPORT SUGGESTED
        builder
            .addCase(importSuggestedExpenseCategories.pending, (state) => {
                state.loading.import = true;
                state.error.import = null;
            })
            .addCase(importSuggestedExpenseCategories.fulfilled, (state) => {
                state.loading.import = false;
            })
            .addCase(importSuggestedExpenseCategories.rejected, (state, action) => {
                state.loading.import = false;
                state.error.import = action.payload || action.error;
            });
    },
});

export default ExpenseCategorySlice.reducer;
