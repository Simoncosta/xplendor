import { createAsyncThunk } from "@reduxjs/toolkit";
import {
    connectMetaAdsApi,
    deleteCarAdCampaignApi,
    disconnectMetaAdsApi,
    getCarAdCampaignsApi,
    getCompanyIntegrationsApi,
    getMetaAdsetsApi,
    getMetaOAuthUrlApi,
    storeCarAdCampaignApi,
    toggleCarAdCampaignApi,
} from "../../helpers/laravel_helper";

export const getMetaOAuthUrl = createAsyncThunk(
    "metaAds/getMetaOAuthUrl",
    async ({ companyId }: { companyId: number }, { rejectWithValue }) => {
        try {
            return await getMetaOAuthUrlApi(companyId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const connectMetaAds = createAsyncThunk(
    "metaAds/connectMetaAds",
    async (
        params: { code: string; state: string; account_id: string; },
        { rejectWithValue }
    ) => {
        try {
            return await connectMetaAdsApi(params);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const disconnectMetaAds = createAsyncThunk(
    "metaAds/disconnectMetaAds",
    async ({ companyId, platform }: { companyId: number; platform: string }, { rejectWithValue }) => {
        try {
            return await disconnectMetaAdsApi(companyId, platform);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const getCompanyIntegrations = createAsyncThunk(
    "metaAds/getCompanyIntegrations",
    async ({ companyId }: { companyId: number }, { rejectWithValue }) => {
        try {
            return await getCompanyIntegrationsApi(companyId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const getMetaAdsets = createAsyncThunk(
    "metaAds/getMetaAdsets",
    async ({ companyId }: { companyId: number }, { rejectWithValue }) => {
        try {
            return await getMetaAdsetsApi(companyId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const getCarAdCampaigns = createAsyncThunk(
    "metaAds/getCarAdCampaigns",
    async ({ companyId, carId }: { companyId: number; carId: number | string }, { rejectWithValue }) => {
        try {
            return await getCarAdCampaignsApi(companyId, carId);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const storeCarAdCampaign = createAsyncThunk(
    "metaAds/storeCarAdCampaign",
    async (
        params: {
            companyId: number;
            carId: number | string;
            data: {
                platform: string;
                campaign_id: string;
                campaign_name: string;
                adset_id: string;
                adset_name: string;
                level: string;
                spend_split_pct: number;
            };
        },
        { rejectWithValue }
    ) => {
        try {
            return await storeCarAdCampaignApi(params.companyId, params.carId, params.data);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const deleteCarAdCampaign = createAsyncThunk(
    "metaAds/deleteCarAdCampaign",
    async ({ companyId, carId, id }: { companyId: number; carId: number | string; id: number }, { rejectWithValue }) => {
        try {
            return await deleteCarAdCampaignApi(companyId, carId, id);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);

export const toggleCarAdCampaign = createAsyncThunk(
    "metaAds/toggleCarAdCampaign",
    async ({ companyId, carId, id }: { companyId: number; carId: number | string; id: number }, { rejectWithValue }) => {
        try {
            return await toggleCarAdCampaignApi(companyId, carId, id);
        } catch (error: any) {
            return rejectWithValue(error?.response?.data || error?.message || error);
        }
    }
);
