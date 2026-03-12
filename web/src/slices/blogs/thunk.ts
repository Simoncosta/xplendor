import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getBlogs as getBlogsApi,
    showBlog as showBlogApi,
    updateBlog as updateBlogApi,
    deleteBlog as deleteBlogApi,
    createBlog as createBlogApi,
} from "../../helpers/laravel_helper";

export const getBlogsPaginate = createAsyncThunk(
    "blog/getBlogsPaginate",
    async (
        params: {
            perPage: number;
            page: number;
            companyId: number;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await getBlogsApi({
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

export const showBlog = createAsyncThunk(
    "blog/getBlogById",
    async (
        params: {
            companyId: number;
            id: number;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await showBlogApi({ companyId: params.companyId, id: params.id });
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateBlog = createAsyncThunk(
    "blog/updateBlog",
    async (
        params: {
            companyId: number;
            id: number;
            formData: FormData;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await updateBlogApi(params.companyId, params.id, params.formData);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const deleteBlog = createAsyncThunk(
    "blog/deleteBlog",
    async (
        params: {
            companyId: number;
            id: number;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await deleteBlogApi(params.companyId, params.id);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createBlog = createAsyncThunk(
    "blog/createBlog",
    async (
        params: {
            companyId: number;
            formData: FormData;
        },
        { rejectWithValue }
    ) => {
        try {
            const response = await createBlogApi(params.companyId, params.formData);
            return response;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
