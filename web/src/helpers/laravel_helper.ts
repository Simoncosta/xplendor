import { APIClient } from "./api_helper";

import * as url from "./url_helper";

const api = new APIClient();

export interface ApiResponse<T> {
    success: boolean;
    message: string;
    data: T;
}

// Gets the logged in user data from local session
export const getLoggedInUser = () => {
    const user = localStorage.getItem("user");
    if (user) return JSON.parse(user);
    return null;
};

// is user is logged in
export const isUserAuthenticated = () => {
    return getLoggedInUser() !== null;
};

// Login Method
export const postApiLogin = (data: any) => api.create(url.POST_FAKE_API_LOGIN, data);
// Logout Method
export const postApiLogout = (data: any) => api.create(url.POST_FAKE_API_LOGOUT, data);
// User By Invite
export const getUserByInvite = (token: string) => api.get(url.GET_USER_BY_INVITE + token);
// Register By Invite
export const postRegisterByInvite = (data: any) => api.create(url.POST_REGISTER_BY_INVITE, data);

// COMPANIES
export const getCompaniesPaginate = (params: { perPage: number; page: number; }) => api.get(url.GET_COMPANIES, params);
export const showCompany = (params: { id: number }) => api.get(url.GET_COMPANIES + "/" + params.id);
export const createCompany = (data: FormData | any) => api.create(url.GET_COMPANIES, data);
export const updateCompany = (id: number, data: FormData | any) => api.create(url.GET_COMPANIES + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });

// DASHBOARDS
export const getAnalyticsDashboard = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_DASHBOARD_APIS);

// META ADS
export const getCompanyIntegrationsApi = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_INTEGRATIONS);
export const getMetaOAuthUrlApi = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_META_INTEGRATIONS + url.GET_META_OAUTH_URL);
export const connectMetaAdsApi = (data: { code: string; state: string; account_id: string; }) =>
    api.create(url.GET_META_INTEGRATIONS + url.POST_META_CALLBACK, data, {
        headers: { "Content-Type": "application/json" }
    });
export const disconnectMetaAdsApi = (companyId: number, platform: string) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_INTEGRATIONS + `/${platform}`);
export const getMetaAdsetsApi = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_META_INTEGRATIONS + url.GET_META_ADSETS);
export const getCarAdCampaignsApi = (companyId: number, carId: number | string) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS);
export const storeCarAdCampaignApi = (
    companyId: number,
    carId: number | string,
    data: {
        platform: string;
        campaign_id: string;
        campaign_name: string;
        adset_id: string;
        adset_name: string;
        level: string;
        spend_split_pct: number;
    }
) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS, data, {
        headers: { "Content-Type": "application/json" }
    });
export const deleteCarAdCampaignApi = (companyId: number, carId: number | string, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS + `/${id}`);
export const toggleCarAdCampaignApi = (companyId: number, carId: number | string, id: number) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS + `/${id}/toggle`, {});

// BLOGS
export const getBlogs = (params: { perPage: number; page: number; companyId: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_BLOGS_APIS, { params });
export const showBlog = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_BLOGS_APIS + "/" + params.id);
export const createBlog = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_BLOGS_APIS, data, { headers: { "Content-Type": "multipart/form-data" } });
export const updateBlog = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_BLOGS_APIS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const deleteBlog = (companyId: number, id: number) => api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_BLOGS_APIS + "/" + id);

// LEADS
export const getLeads = (params: { perPage: number; page: number; companyId: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_LEADS_APIS, { params });

// CARS
export const getCarsPaginate = (
    params: {
        perPage: number;
        page: number;
        companyId: number;
        status?: 'active' | 'sold' | 'draft';
        carBrandIds?: number[];
        carModelIds?: number[];
        mincost?: number;
        maxcost?: number;
        sort_by?: string;
        sort_direction?: 'asc' | 'desc';
    }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARS, {
        params: {
            perPage: params.perPage,
            page: params.page,
            status: params.status,
            car_brand_id: params.carBrandIds?.join(",") ?? undefined,
            car_model_id: params.carModelIds?.join(",") ?? undefined,
            mincost: params.mincost,
            maxcost: params.maxcost,
            sort_by: params.sort_by,
            sort_direction: params.sort_direction,
        }
    });
export const showCar = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARS + "/" + params.id);
export const createCar = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS, data, { headers: { "Content-Type": "multipart/form-data" } });
export const updateCar = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const closeCarSale = (companyId: number, carId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + carId + url.GET_CAR_SALES, data, { headers: { "Content-Type": "multipart/form-data" } });
export const analyticsCar = (companyId: number, carId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + carId + "/analytics");
export const generateCarMarketingApi = (companyId: number, carId?: number) =>
    api.create(
        url.GET_COMPANIES + `/${companyId}` + url.GET_MARKETING_IDEAS + url.POST_MARKETING_IDEAS_GENERATE,
        carId ? { car_id: carId } : {}
    );
export const getCarMarketingApi = (companyId: number, carId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + carId + "/marketing");

// CAR AI ANALISES
export const postCarAiAnalyses = (companyId: number, carId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS_ANALYSES + `/${carId}`, {});
export const postCarRecalculate = (companyId: number, carId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/potential-score/recalculate`, {});

// USERS
export const getUsersPaginate = (params: { perPage: number; page: number; companyId: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_USERS_APIS, { params });
export const showUser = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_USERS_APIS + "/" + params.id);
export const updateUser = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_USERS_APIS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const createUser = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_USERS_APIS, data, { headers: { "Content-Type": "multipart/form-data" } });

// CARMINE
export const showCarmine = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARMINE_APIS + "/" + params.id);
export const createCarmine = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS, data);
export const updateCarmine = (companyId: number, id: number, data: FormData | any) => api.put(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS + "/" + id, data);
export const syncCarmine = (companyId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS + "/sync", {});

// CAR BRANDS
export const getCarBrands = () => api.get(url.GET_CAR_BRANDS);

// CAR MODELS
export const getCarModels = (brandId: number | number[]) => api.get(url.GET_CAR_MODELS, {
    params: {
        car_brand_id: typeof brandId === "number" ? brandId.toString() : brandId.join(","),
    }
});

// DISTRICTS
export const getDistricts = () => api.get(url.GET_DISTRICTS);

// MUNICIPALITIES
export const getMunicipalities = (districtId: number) => api.get(`${url.GET_DISTRICTS}/${districtId}/municipalities`);
export const getParishes = (municipalityId: number) => api.get(`/municipalities/${municipalityId}/parishes`);
