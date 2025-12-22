'use client';

import { createSlice, createAsyncThunk, PayloadAction } from '@reduxjs/toolkit';
import axiosInstance from '@/services/axiosInstance';
import Cookies from 'js-cookie';

/* ================================
   TYPES
================================ */

interface AuthState {
    user: any | null;
    token: string | null;
    loading: boolean;
    error: string | null;
    locked: boolean;
}

/* ================================
   HELPERS
================================ */

const safeParse = (value: string | null) => {
    try {
        if (!value || value === 'undefined' || value === 'null') return null;
        return JSON.parse(value);
    } catch {
        return null;
    }
};

/* ================================
   INITIAL STATE
================================ */

const initialState: AuthState = {
    user: typeof window !== 'undefined'
        ? safeParse(localStorage.getItem('user'))
        : null,

    token: typeof window !== 'undefined'
        ? localStorage.getItem('token')
        : null,

    loading: false,
    error: null,

    locked: typeof window !== 'undefined'
        ? localStorage.getItem('locked') === 'true'
        : false,
};

/* ================================
   THUNKS
================================ */

// LOGIN
export const login = createAsyncThunk(
    'auth/login',
    async (
        { email, password }: { email: string; password: string },
        thunkAPI
    ) => {
        try {
            const response = await axiosInstance.post('/v1/login', {
                email,
                password,
            });
            return response.data;
        } catch (err: any) {
            return thunkAPI.rejectWithValue(
                err.response?.data?.message || 'Erro no login'
            );
        }
    }
);

// REGISTER
export const registerByInvite = createAsyncThunk(
    'auth/register',
    async (
        { password, password_confirmation, token }: { password: string; password_confirmation: string; token: string },
        thunkAPI
    ) => {
        try {
            const response = await axiosInstance.post('/v1/register-by-invite', {
                password,
                password_confirmation,
                token,
            });
            return response.data;
        } catch (err: any) {
            return thunkAPI.rejectWithValue(
                err.response?.data?.message || 'Erro no login'
            );
        }
    }
);

// FETCH ME (fonte da verdade)
export const fetchMe = createAsyncThunk(
    'auth/fetchMe',
    async (user, thunkAPI) => {
        try {
            // @ts-ignore
            const response = await axiosInstance.get(`/v1/companies/${user.company_id}/users/${user.id}`);
            return response.data;
        } catch (err: any) {
            return thunkAPI.rejectWithValue(
                err.response?.data?.message || 'Erro ao carregar usuário'
            );
        }
    }
);

/* ================================
   SLICE
================================ */

export const authSlice = createSlice({
    name: 'auth',
    initialState,

    reducers: {
        // LOGOUT
        logout(state) {
            state.user = null;
            state.token = null;
            state.locked = false;

            localStorage.removeItem('user');
            localStorage.removeItem('token');
            localStorage.removeItem('locked');

            Cookies.remove('token');
            Cookies.remove('locked');
        },

        // LOCK SCREEN
        lockScreen(state) {
            state.locked = true;
            localStorage.setItem('locked', 'true');
            Cookies.set('locked', 'true', {
                secure: true,
                sameSite: 'strict',
            });
        },

        // UNLOCK SCREEN
        unlockScreen(state) {
            state.locked = false;
            localStorage.removeItem('locked');
            Cookies.remove('locked');
        },
    },

    extraReducers: (builder) => {
        /* ======================
           LOGIN
        ====================== */

        builder
            .addCase(login.pending, (state) => {
                state.loading = true;
                state.error = null;
            })

            .addCase(login.fulfilled, (state, action: PayloadAction<any>) => {
                state.loading = false;

                const payload = action.payload.data;

                state.token = payload.token;

                localStorage.setItem('token', payload.token);
                Cookies.set('token', payload.token, {
                    expires: 7,
                    secure: true,
                    sameSite: 'strict',
                });
            })

            .addCase(login.rejected, (state, action: PayloadAction<any>) => {
                state.loading = false;
                state.error = action.payload;
            });

        /* ======================
           FETCH ME
        ====================== */

        builder
            .addCase(fetchMe.pending, (state) => {
                state.loading = true;
            })

            .addCase(fetchMe.fulfilled, (state, action: PayloadAction<any>) => {
                state.loading = false;

                state.user = action.payload.data;

                localStorage.setItem('user', JSON.stringify(state.user));
            })

            .addCase(fetchMe.rejected, (state) => {
                state.loading = false;
                state.user = null;
                state.token = null;

                localStorage.removeItem('user');
                localStorage.removeItem('token');
                Cookies.remove('token');
            });

        /* ======================
           REGISTER
        ====================== */

        builder
            .addCase(registerByInvite.pending, (state) => {
                state.loading = true;
                state.error = null;
            })

            .addCase(registerByInvite.fulfilled, (state, action: PayloadAction<any>) => {
                state.loading = false;

                const payload = action.payload.data;

                state.token = payload.token;

                localStorage.setItem('token', payload.token);
                Cookies.set('token', payload.token, {
                    expires: 7,
                    secure: true,
                    sameSite: 'strict',
                });
            })

            .addCase(registerByInvite.rejected, (state, action: PayloadAction<any>) => {
                state.loading = false;
                state.error = action.payload;
            });
    },
});

/* ================================
   EXPORTS
================================ */

export const {
    logout,
    lockScreen,
    unlockScreen,
} = authSlice.actions;

export default authSlice.reducer;