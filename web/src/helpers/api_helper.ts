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