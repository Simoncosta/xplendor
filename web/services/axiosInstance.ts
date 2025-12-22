import axios from 'axios';
import Cookies from 'js-cookie';

const api = axios.create({
    baseURL: `${process.env.NEXT_PUBLIC_API_URL}/api`,
    withCredentials: false, // importante para evitar problemas de CORS
});

// Interceptor de REQUEST — injeta o token
api.interceptors.request.use((config) => {
    if (typeof window !== 'undefined') {
        const lsToken = localStorage.getItem('token');
        const cookieToken = Cookies.get('token');

        const token = lsToken || cookieToken;

        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
    }

    return config;
});

api.interceptors.response.use(
    (response) => response,

    (error) => {
        const status = error.response?.status;

        if (typeof window === 'undefined') {
            return Promise.reject(error);
        }

        if (status === 401) {
            // limpa tudo
            localStorage.removeItem('user');
            localStorage.removeItem('token');
            Cookies.remove('token');
            Cookies.remove('locked');

            // redireciona de forma segura
            if (!window.location.pathname.startsWith('/auth')) {
                window.location.href = '/auth/login';
            }
        }

        return Promise.reject(error);
    }
);

export default api;