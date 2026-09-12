import { createSlice } from "@reduxjs/toolkit";
import {
    getDocumentTemplates,
    createDocumentTemplate,
    updateDocumentTemplate,
    deleteDocumentTemplate,
} from "./thunk";
import { IDocumentTemplate } from "common/models/documentTemplate.model";

const initialState = {
    data: {
        templates: [] as IDocumentTemplate[],
    },
    loading: {
        list: false,
        create: false,
        update: false,
        remove: false,
    },
    error: {
        list: null as any,
        create: null as any,
        update: null as any,
        remove: null as any,
    },
};

const upsert = (list: IDocumentTemplate[], item: IDocumentTemplate): IDocumentTemplate[] => {
    const i = list.findIndex((t) => t.id === item.id);
    if (i === -1) return [...list, item];
    const next = [...list];
    next[i] = item;
    return next;
};

const slice = createSlice({
    name: "documentTemplates",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getDocumentTemplates.pending, (state) => { state.loading.list = true; state.error.list = null; })
            .addCase(getDocumentTemplates.fulfilled, (state, action: any) => {
                state.loading.list = false;
                state.data.templates = action.payload?.data ?? [];
            })
            .addCase(getDocumentTemplates.rejected, (state, action) => { state.loading.list = false; state.error.list = action.payload || action.error; });

        builder
            .addCase(createDocumentTemplate.pending, (state) => { state.loading.create = true; state.error.create = null; })
            .addCase(createDocumentTemplate.fulfilled, (state, action: any) => {
                state.loading.create = false;
                if (action.payload?.data) state.data.templates = upsert(state.data.templates, action.payload.data);
            })
            .addCase(createDocumentTemplate.rejected, (state, action) => { state.loading.create = false; state.error.create = action.payload || action.error; });

        builder
            .addCase(updateDocumentTemplate.pending, (state) => { state.loading.update = true; state.error.update = null; })
            .addCase(updateDocumentTemplate.fulfilled, (state, action: any) => {
                state.loading.update = false;
                if (action.payload?.data) state.data.templates = upsert(state.data.templates, action.payload.data);
            })
            .addCase(updateDocumentTemplate.rejected, (state, action) => { state.loading.update = false; state.error.update = action.payload || action.error; });

        builder
            .addCase(deleteDocumentTemplate.pending, (state) => { state.loading.remove = true; state.error.remove = null; })
            .addCase(deleteDocumentTemplate.fulfilled, (state, action: any) => {
                state.loading.remove = false;
                state.data.templates = state.data.templates.filter((t) => t.id !== action.payload);
            })
            .addCase(deleteDocumentTemplate.rejected, (state, action) => { state.loading.remove = false; state.error.remove = action.payload || action.error; });
    },
});

export default slice.reducer;
