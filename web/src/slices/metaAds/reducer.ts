import { createSlice } from "@reduxjs/toolkit";
import {
    connectMetaAds,
    deleteCarAdCampaign,
    disconnectMetaAds,
    getCarAdCampaigns,
    getCompanyIntegrations,
    getMetaAdsets,
    getMetaOAuthUrl,
    storeCarAdCampaign,
    toggleCarAdCampaign,
} from "./thunk";

const initialState = {
    data: {
        oauthUrl: null as string | null,
        integrations: [] as any[],
        adsets: [] as any[],
        carAdCampaigns: [] as any[],
    },
    loading: {
        show: false,
        connect: false,
        disconnect: false,
        list: false,
        sync: false,
        create: false,
        delete: false,
        update: false,
    },
    error: {
        show: null as any,
        connect: null as any,
        disconnect: null as any,
        list: null as any,
        sync: null as any,
        create: null as any,
        delete: null as any,
        update: null as any,
    },
};

const MetaAdsSlice = createSlice({
    name: "metaAds",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getMetaOAuthUrl.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(getMetaOAuthUrl.fulfilled, (state, action) => {
                state.loading.show = false;
                state.error.show = null;
                state.data.oauthUrl = action.payload.data?.url ?? null;
            })
            .addCase(getMetaOAuthUrl.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });

        builder
            .addCase(connectMetaAds.pending, (state) => {
                state.loading.connect = true;
                state.error.connect = null;
            })
            .addCase(connectMetaAds.fulfilled, (state) => {
                state.loading.connect = false;
                state.error.connect = null;
            })
            .addCase(connectMetaAds.rejected, (state, action) => {
                state.loading.connect = false;
                state.error.connect = action.payload || action.error;
            });

        builder
            .addCase(disconnectMetaAds.pending, (state) => {
                state.loading.disconnect = true;
                state.error.disconnect = null;
            })
            .addCase(disconnectMetaAds.fulfilled, (state) => {
                state.loading.disconnect = false;
                state.error.disconnect = null;
            })
            .addCase(disconnectMetaAds.rejected, (state, action) => {
                state.loading.disconnect = false;
                state.error.disconnect = action.payload || action.error;
            });

        builder
            .addCase(getCompanyIntegrations.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getCompanyIntegrations.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.integrations = action.payload.data ?? [];
            })
            .addCase(getCompanyIntegrations.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        builder
            .addCase(getMetaAdsets.pending, (state) => {
                state.loading.sync = true;
                state.error.sync = null;
            })
            .addCase(getMetaAdsets.fulfilled, (state, action) => {
                state.loading.sync = false;
                state.error.sync = null;
                state.data.adsets = action.payload.data ?? [];
            })
            .addCase(getMetaAdsets.rejected, (state, action) => {
                state.loading.sync = false;
                state.error.sync = action.payload || action.error;
            });

        builder
            .addCase(getCarAdCampaigns.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getCarAdCampaigns.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.carAdCampaigns = action.payload.data ?? [];
            })
            .addCase(getCarAdCampaigns.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        builder
            .addCase(storeCarAdCampaign.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(storeCarAdCampaign.fulfilled, (state) => {
                state.loading.create = false;
                state.error.create = null;
            })
            .addCase(storeCarAdCampaign.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        builder
            .addCase(deleteCarAdCampaign.pending, (state) => {
                state.loading.delete = true;
                state.error.delete = null;
            })
            .addCase(deleteCarAdCampaign.fulfilled, (state) => {
                state.loading.delete = false;
                state.error.delete = null;
            })
            .addCase(deleteCarAdCampaign.rejected, (state, action) => {
                state.loading.delete = false;
                state.error.delete = action.payload || action.error;
            });

        builder
            .addCase(toggleCarAdCampaign.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(toggleCarAdCampaign.fulfilled, (state) => {
                state.loading.update = false;
                state.error.update = null;
            })
            .addCase(toggleCarAdCampaign.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });
    },
});

export default MetaAdsSlice.reducer;
