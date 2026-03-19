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
    oauthUrl: null as string | null,
    integrations: [] as any[],
    adsets: [] as any[],
    carAdCampaigns: [] as any[],

    loadingOAuthUrl: false,
    loadingConnect: false,
    loadingDisconnect: false,
    loadingIntegrations: false,
    loadingAdsets: false,
    loadingCarAdCampaigns: false,
    loadingStoreCarAdCampaign: false,
    loadingDeleteCarAdCampaign: false,
    loadingToggleCarAdCampaign: false,

    errorOAuthUrl: null as any,
    errorConnect: null as any,
    errorDisconnect: null as any,
    errorIntegrations: null as any,
    errorAdsets: null as any,
    errorCarAdCampaigns: null as any,
    errorStoreCarAdCampaign: null as any,
    errorDeleteCarAdCampaign: null as any,
    errorToggleCarAdCampaign: null as any,
};

const MetaAdsSlice = createSlice({
    name: "metaAds",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(getMetaOAuthUrl.pending, (state) => {
                state.loadingOAuthUrl = true;
                state.errorOAuthUrl = null;
            })
            .addCase(getMetaOAuthUrl.fulfilled, (state, action) => {
                state.loadingOAuthUrl = false;
                state.oauthUrl = action.payload.data?.url ?? null;
            })
            .addCase(getMetaOAuthUrl.rejected, (state, action) => {
                state.loadingOAuthUrl = false;
                state.errorOAuthUrl = action.payload || action.error;
            });

        builder
            .addCase(connectMetaAds.pending, (state) => {
                state.loadingConnect = true;
                state.errorConnect = null;
            })
            .addCase(connectMetaAds.fulfilled, (state) => {
                state.loadingConnect = false;
            })
            .addCase(connectMetaAds.rejected, (state, action) => {
                state.loadingConnect = false;
                state.errorConnect = action.payload || action.error;
            });

        builder
            .addCase(disconnectMetaAds.pending, (state) => {
                state.loadingDisconnect = true;
                state.errorDisconnect = null;
            })
            .addCase(disconnectMetaAds.fulfilled, (state) => {
                state.loadingDisconnect = false;
            })
            .addCase(disconnectMetaAds.rejected, (state, action) => {
                state.loadingDisconnect = false;
                state.errorDisconnect = action.payload || action.error;
            });

        builder
            .addCase(getCompanyIntegrations.pending, (state) => {
                state.loadingIntegrations = true;
                state.errorIntegrations = null;
            })
            .addCase(getCompanyIntegrations.fulfilled, (state, action) => {
                state.loadingIntegrations = false;
                state.integrations = action.payload.data ?? [];
            })
            .addCase(getCompanyIntegrations.rejected, (state, action) => {
                state.loadingIntegrations = false;
                state.errorIntegrations = action.payload || action.error;
            });

        builder
            .addCase(getMetaAdsets.pending, (state) => {
                state.loadingAdsets = true;
                state.errorAdsets = null;
            })
            .addCase(getMetaAdsets.fulfilled, (state, action) => {
                state.loadingAdsets = false;
                state.adsets = action.payload.data ?? [];
            })
            .addCase(getMetaAdsets.rejected, (state, action) => {
                state.loadingAdsets = false;
                state.errorAdsets = action.payload || action.error;
            });

        builder
            .addCase(getCarAdCampaigns.pending, (state) => {
                state.loadingCarAdCampaigns = true;
                state.errorCarAdCampaigns = null;
            })
            .addCase(getCarAdCampaigns.fulfilled, (state, action) => {
                state.loadingCarAdCampaigns = false;
                state.carAdCampaigns = action.payload.data ?? [];
            })
            .addCase(getCarAdCampaigns.rejected, (state, action) => {
                state.loadingCarAdCampaigns = false;
                state.errorCarAdCampaigns = action.payload || action.error;
            });

        builder
            .addCase(storeCarAdCampaign.pending, (state) => {
                state.loadingStoreCarAdCampaign = true;
                state.errorStoreCarAdCampaign = null;
            })
            .addCase(storeCarAdCampaign.fulfilled, (state) => {
                state.loadingStoreCarAdCampaign = false;
            })
            .addCase(storeCarAdCampaign.rejected, (state, action) => {
                state.loadingStoreCarAdCampaign = false;
                state.errorStoreCarAdCampaign = action.payload || action.error;
            });

        builder
            .addCase(deleteCarAdCampaign.pending, (state) => {
                state.loadingDeleteCarAdCampaign = true;
                state.errorDeleteCarAdCampaign = null;
            })
            .addCase(deleteCarAdCampaign.fulfilled, (state) => {
                state.loadingDeleteCarAdCampaign = false;
            })
            .addCase(deleteCarAdCampaign.rejected, (state, action) => {
                state.loadingDeleteCarAdCampaign = false;
                state.errorDeleteCarAdCampaign = action.payload || action.error;
            });

        builder
            .addCase(toggleCarAdCampaign.pending, (state) => {
                state.loadingToggleCarAdCampaign = true;
                state.errorToggleCarAdCampaign = null;
            })
            .addCase(toggleCarAdCampaign.fulfilled, (state) => {
                state.loadingToggleCarAdCampaign = false;
            })
            .addCase(toggleCarAdCampaign.rejected, (state, action) => {
                state.loadingToggleCarAdCampaign = false;
                state.errorToggleCarAdCampaign = action.payload || action.error;
            });
    },
});

export default MetaAdsSlice.reducer;
