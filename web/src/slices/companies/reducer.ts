import { createSlice } from "@reduxjs/toolkit";
import { getCompaniesPaginate, showCompany } from "./thunk";
import { ICompany } from "common/models/company.model";

const initialState = {
    companies: [] as ICompany[],
    meta: null as any,

    company: null as ICompany | null,

    loadingList: false,
    loadingShow: false,

    errorList: null as any,
    errorShow: null as any,
};

const CompanySlice = createSlice({
    name: "company",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getCompaniesPaginate.pending, (state) => {
                state.loadingList = true;
                state.errorList = null;
            })
            .addCase(getCompaniesPaginate.fulfilled, (state, action) => {
                state.loadingList = false;
                state.companies = action.payload.data.data;
                state.meta = action.payload.data;
            })
            .addCase(getCompaniesPaginate.rejected, (state, action) => {
                state.loadingList = false;
                state.errorList = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showCompany.pending, (state) => {
                state.loadingShow = true;
                state.errorShow = null;
            })
            .addCase(showCompany.fulfilled, (state, action) => {
                state.loadingShow = false;
                state.company = action.payload.data;
            })
            .addCase(showCompany.rejected, (state, action) => {
                state.loadingShow = false;
                state.errorShow = action.payload || action.error;
            });
    },
});

export default CompanySlice.reducer;