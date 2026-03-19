import { createSlice } from "@reduxjs/toolkit";
import { createCompany, getCompaniesPaginate, showCompany, updateCompany } from "./thunk";
import { ICompany } from "common/models/company.model";

const initialState = {
    data: {
        companies: [] as ICompany[],
        meta: null as any,
        company: null as ICompany | null,
    },
    loading: {
        list: false,
        show: false,
        create: false,
        update: false,
    },
    error: {
        list: null as any,
        show: null as any,
        create: null as any,
        update: null as any,
    },
};

const CompanySlice = createSlice({
    name: "company",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getCompaniesPaginate.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getCompaniesPaginate.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.companies = action.payload.data.data;
                state.data.meta = action.payload.data;
            })
            .addCase(getCompaniesPaginate.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showCompany.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(showCompany.fulfilled, (state, action) => {
                state.loading.show = false;
                state.error.show = null;
                state.data.company = action.payload.data;
            })
            .addCase(showCompany.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });

        // CREATE
        builder
            .addCase(createCompany.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createCompany.fulfilled, (state, action) => {
                state.loading.create = false;
                state.error.create = null;
                state.data.company = action.payload.data;
            })
            .addCase(createCompany.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateCompany.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateCompany.fulfilled, (state, action) => {
                state.loading.update = false;
                state.error.update = null;
                state.data.company = action.payload.data;
            })
            .addCase(updateCompany.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });
    },
});

export default CompanySlice.reducer;
