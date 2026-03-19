import { createSlice } from "@reduxjs/toolkit";
import { createBlog, deleteBlog, getBlogsPaginate, showBlog, updateBlog } from "./thunk";

const initialState = {
    data: {
        blogs: [] as any[],
        meta: null as any,
        blog: null as any | null,
    },
    loading: {
        list: false,
        show: false,
        create: false,
        update: false,
        delete: false,
    },
    error: {
        list: null as any,
        show: null as any,
        create: null as any,
        update: null as any,
        delete: null as any,
    },
};

const BlogSlice = createSlice({
    name: "blog",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getBlogsPaginate.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getBlogsPaginate.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.blogs = action.payload.data.data;
                state.data.meta = action.payload.data;
            })
            .addCase(getBlogsPaginate.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showBlog.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(showBlog.fulfilled, (state, action) => {
                state.loading.show = false;
                state.error.show = null;
                state.data.blog = action.payload.data;
            })
            .addCase(showBlog.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });

        builder
            .addCase(createBlog.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createBlog.fulfilled, (state, action) => {
                state.loading.create = false;
                state.error.create = null;
                state.data.blog = action.payload.data;
            })
            .addCase(createBlog.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        builder
            .addCase(updateBlog.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateBlog.fulfilled, (state, action) => {
                state.loading.update = false;
                state.error.update = null;
                state.data.blog = action.payload.data;
            })
            .addCase(updateBlog.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });

        builder
            .addCase(deleteBlog.pending, (state) => {
                state.loading.delete = true;
                state.error.delete = null;
            })
            .addCase(deleteBlog.fulfilled, (state) => {
                state.loading.delete = false;
                state.error.delete = null;
            })
            .addCase(deleteBlog.rejected, (state, action) => {
                state.loading.delete = false;
                state.error.delete = action.payload || action.error;
            });
    },
});

export default BlogSlice.reducer;
