import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    getCustomers as getCustomersApi,
    showCustomer as showCustomerApi,
    createCustomer as createCustomerApi,
    updateCustomer as updateCustomerApi,
    deleteCustomer as deleteCustomerApi,
} from "../../helpers/laravel_helper";
import { ICustomerPayload } from "common/models/customer.model";

export const getCustomers = createAsyncThunk(
    "customer/getCustomers",
    async (params: { companyId: number; perPage?: number; page?: number; search?: string; only_active?: number }, { rejectWithValue }) => {
        try {
            const { companyId, ...rest } = params;
            return await getCustomersApi(companyId, rest);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const showCustomer = createAsyncThunk(
    "customer/showCustomer",
    async (params: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            return await showCustomerApi(params.companyId, params.id);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const createCustomer = createAsyncThunk(
    "customer/createCustomer",
    async (params: { companyId: number; data: ICustomerPayload }, { rejectWithValue }) => {
        try {
            return await createCustomerApi(params.companyId, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const updateCustomer = createAsyncThunk(
    "customer/updateCustomer",
    async (params: { companyId: number; id: number; data: Partial<ICustomerPayload> }, { rejectWithValue }) => {
        try {
            return await updateCustomerApi(params.companyId, params.id, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const deleteCustomer = createAsyncThunk(
    "customer/deleteCustomer",
    async (params: { companyId: number; id: number }, { rejectWithValue }) => {
        try {
            await deleteCustomerApi(params.companyId, params.id);
            return params.id;
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
