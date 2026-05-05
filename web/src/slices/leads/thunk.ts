import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getLeads as getLeadsApi,
    updateLead as updateLeadApi,
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

export const updateLeadStatus = createAsyncThunk(
    "leads/updateStatus",
    async ({ leadId, status }: { leadId: number; status: string }, { rejectWithValue }) => {
        try {
            const authUser = sessionStorage.getItem("authUser");
            const companyId = authUser ? JSON.parse(authUser)?.company_id : null;

            if (!companyId) {
                return rejectWithValue("Empresa não encontrada para atualizar lead");
            }

            const response = await updateLeadApi(companyId, leadId, { status });
            return response.data;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error || "Erro ao atualizar lead");
        }
    }
);
