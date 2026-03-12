import { createSlice } from "@reduxjs/toolkit";
import { getBlogsPaginate, showBlog } from "./thunk";

const initialState = {
    blogs: [] as any[],
    meta: null as any,

    blog: null as any | null,

    loadingList: false,
    loadingShow: false,

    errorList: null as any,
    errorShow: null as any,
};

const BlogSlice = createSlice({
    name: "blog",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getBlogsPaginate.pending, (state) => {
                state.loadingList = true;
                state.errorList = null;
            })
            .addCase(getBlogsPaginate.fulfilled, (state, action) => {
                state.loadingList = false;
                state.blogs = action.payload.data.data;
                state.meta = action.payload.data;
            })
            .addCase(getBlogsPaginate.rejected, (state, action) => {
                state.loadingList = false;
                state.errorList = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showBlog.pending, (state) => {
                state.loadingShow = true;
                state.errorShow = null;
            })
            .addCase(showBlog.fulfilled, (state, action) => {
                state.loadingShow = false;
                state.blog = action.payload.data;
            })
            .addCase(showBlog.rejected, (state, action) => {
                state.loadingShow = false;
                state.errorShow = action.payload || action.error;
            });
    },
});

export default BlogSlice.reducer;