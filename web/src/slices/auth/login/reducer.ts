import { createSlice } from "@reduxjs/toolkit";

export const initialState = {
    data: {
        user: {},
        isUserLogout: false,
        errorMsg: false,
    },
    loading: {
        auth: false,
    },
    error: {
        auth: "",
    },
};

const loginSlice = createSlice({
    name: "login",
    initialState,
    reducers: {
        apiError(state, action) {
            state.error.auth = action.payload.data;
            state.loading.auth = false;
            state.data.isUserLogout = false;
            state.data.errorMsg = true;
        },
        loginSuccess(state, action) {
            state.data.user = action.payload;
            state.loading.auth = false;
            state.error.auth = "";
            state.data.errorMsg = false;
        },
        logoutUserSuccess(state, action) {
            state.data.isUserLogout = true;
        },
        reset_login_flag(state) {
            state.error.auth = "";
            state.loading.auth = false;
            state.data.errorMsg = false;
        }
    },
});

export const {
    apiError,
    loginSuccess,
    logoutUserSuccess,
    reset_login_flag
} = loginSlice.actions

export default loginSlice.reducer;
