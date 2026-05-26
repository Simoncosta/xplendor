import { createSlice } from "@reduxjs/toolkit";
import { getLeadsPaginate, updateLeadStatus } from "./thunk";

const initialState = {
    data: {
        leads: [] as any[],
        meta: null as any,
        lead: null as any | null,
    },
    loading: {
        list: false,
        update: false,
    },
    loadingUpdate: false,
    error: {
        list: null as any,
        update: null as any,
    },
    statusUpdatePrevious: null as { id: number; status: string } | null,
};

const LeadSlice = createSlice({
    name: "blog",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getLeadsPaginate.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getLeadsPaginate.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.leads = action.payload.data.data;
                state.data.meta = action.payload.data;
            })
            .addCase(getLeadsPaginate.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        // UPDATE STATUS
        builder
            .addCase(updateLeadStatus.pending, (state, action) => {
                state.loading.update = true;
                state.loadingUpdate = true;
                state.error.update = null;

                const { leadId, status } = action.meta.arg;
                const currentLead = state.data.leads.find((lead: any) => lead.id === leadId);
                state.statusUpdatePrevious = currentLead
                    ? { id: currentLead.id, status: currentLead.status }
                    : null;

                state.data.leads = state.data.leads.map((lead: any) =>
                    lead.id === leadId ? { ...lead, status } : lead
                );
            })
            .addCase(updateLeadStatus.fulfilled, (state, action) => {
                state.loading.update = false;
                state.loadingUpdate = false;
                state.error.update = null;
                state.statusUpdatePrevious = null;

                const updatedLead = action.payload;

                state.data.leads = state.data.leads.map((lead: any) =>
                    lead.id === updatedLead.id
                        ? {
                            ...lead,
                            ...updatedLead,
                        }
                        : lead
                );
            })
            .addCase(updateLeadStatus.rejected, (state, action) => {
                state.loading.update = false;
                state.loadingUpdate = false;
                state.error.update = action.payload || action.error;

                if (state.statusUpdatePrevious) {
                    const previous = state.statusUpdatePrevious;
                    state.data.leads = state.data.leads.map((lead: any) =>
                        lead.id === previous.id ? { ...lead, status: previous.status } : lead
                    );
                }

                state.statusUpdatePrevious = null;
            });
    },
});

export default LeadSlice.reducer;
