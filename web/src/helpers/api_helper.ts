import axios, { AxiosResponse, AxiosRequestConfig } from 'axios';
import config from "../config";

const { api } = config;

axios.defaults.baseURL = api.API_URL;
axios.defaults.headers.post["Content-Type"] = "multipart/form-data";

axios.interceptors.request.use(
    function (config) {
        const authUser = sessionStorage.getItem("authUser");

        if (authUser) {
            const parsedUser = JSON.parse(authUser);
            const token = parsedUser?.token;

            if (token) {
                config.headers = config.headers || {};
                config.headers.Authorization = `Bearer ${token}`;
            }
        } else if (config.headers?.Authorization) {
            delete config.headers.Authorization;
        }

        return config;
    },
    function (error) {
        return Promise.reject(error);
    }
);

// intercepting to capture errors
axios.interceptors.response.use(
    function (response) {
        return response.data ? response.data : response;
    },
    function (error) {
        // MS2.g item 1 — Qualquer 4xx com body-objecto preservado para os
        // catches lerem o shape directamente. Generalização da regra T2
        // (que era 422-only) — bug latente confirmado: o ramo 429 do
        // handleRefresh também nunca disparava porque o status se perdia.
        // `__status` (prefixo __ para não colidir com payloads reais) deixa
        // o catch distinguir 422/429/etc. sem reler `error.response`.
        // Caminho exclusivo — 5xx/network mantêm comportamento legacy (string).
        // Ver T2 / X7.1 / MS1.e — 4.ª ocorrência do padrão do interceptor.
        const status = error?.response?.status;
        const body   = error?.response?.data;
        if (
            typeof status === "number" &&
            status >= 400 && status < 500 &&
            body && typeof body === "object"
        ) {
            return Promise.reject({ ...body, __status: status });
        }

        // Any status codes that falls outside the range of 2xx cause this function to trigger
        let message;
        switch (error.status) {
            case 500:
                message = "Internal Server Error";
                break;
            case 401:
                message = "Invalid credentials";
                break;
            case 404:
                message = "Sorry! the data you are looking for could not be found";
                break;
            default:
                message = error.message || error;
        }
        return Promise.reject(message);
    }
);
/**
 * Sets the default authorization
 * @param {*} token
 */
const setAuthorization = (token: string | null) => {
    if (token) {
        axios.defaults.headers.common["Authorization"] = "Bearer " + token;
    } else {
        delete axios.defaults.headers.common["Authorization"];
    }
};

class APIClient {
    /**
     * Fetches data from the given URL
     */
    get = (url: string, params?: any): Promise<AxiosResponse> => {
        let response: Promise<AxiosResponse>;

        let paramKeys: string[] = [];

        if (params) {
            Object.keys(params).map(key => {
                paramKeys.push(key + '=' + params[key]);
                return paramKeys;
            });

            const queryString = paramKeys && paramKeys.length ? paramKeys.join('&') : "";
            response = axios.get(`${url}?${queryString}`, params);
        } else {
            response = axios.get(`${url}`, params);
        }

        return response;
    };

    /**
     * Posts the given data to the URL
     */
    create = (url: string, data: any, config?: AxiosRequestConfig): Promise<AxiosResponse> => {
        return axios.post(url, data, config);
    };

    /**
     * Updates data
     */
    update = (url: string, data: any): Promise<AxiosResponse> => {
        return axios.patch(url, data);
    };

    put = (url: string, data: any): Promise<AxiosResponse> => {
        return axios.put(url, data);
    };

    /**
     * Deletes data
     */
    delete = (url: string, config?: AxiosRequestConfig): Promise<AxiosResponse> => {
        return axios.delete(url, { ...config });
    };
}

const getLoggedinUser = () => {
    const user = sessionStorage.getItem("authUser");
    if (!user) {
        return null;
    } else {
        return JSON.parse(user);
    }
};

export { APIClient, setAuthorization, getLoggedinUser };