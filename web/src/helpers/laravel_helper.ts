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

// CARS
export const getCarsPaginate = (
    params: {
        perPage: number;
        page: number;
        companyId: number;
        status?: 'active' | 'sold' | 'draft';
    }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARS, {
        params: {
            perPage: params.perPage,
            page: params.page,
            status: params.status
        }
    });
export const showCar = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARS + "/" + params.id);
export const createCar = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS, data, { headers: { "Content-Type": "multipart/form-data" } });
export const updateCar = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });

// CAR BRANDS
export const getCarBrands = () => api.get(url.GET_CAR_BRANDS);

// CAR MODELS
export const getCarModels = (brandId: number) => api.get(url.GET_CAR_MODELS, {
    params: {
        car_brand_id: brandId,
    }
});

// DISTRICTS
export const getDistricts = () => api.get(url.GET_DISTRICTS);

// MUNICIPALITIES
export const getMunicipalities = (districtId: number) => api.get(`${url.GET_DISTRICTS}/${districtId}/municipalities`);
export const getParishes = (municipalityId: number) => api.get(`/municipalities/${municipalityId}/parishes`);

