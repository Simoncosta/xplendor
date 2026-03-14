//Include Both Helper File with needed methods
import { setAuthorization } from "helpers/api_helper";
import {
    postApiLogin,
    postApiLogout,
    postRegisterByInvite as postRegisterByInviteApi,
} from "../../../helpers/laravel_helper";

import { loginSuccess, logoutUserSuccess, apiError, reset_login_flag } from './reducer';

export const registerByInvite =
    (payload: { token: string; password: string; password_confirmation: string }, navigate: any) =>
        async (dispatch: any) => {
            try {
                const res = await postRegisterByInviteApi(payload);

                const authData = res.data.data ?? res;

                sessionStorage.setItem("authUser", JSON.stringify(authData.data));

                dispatch(loginSuccess(authData.data));
                navigate("/dashboard");
            } catch (error: any) {
                dispatch(apiError(error));
            }
        };

export const loginUser = (user: any, history: any) => async (dispatch: any) => {
    try {
        let response;

        if (process.env.REACT_APP_DEFAULTAUTH === "jwt") {
            response = postApiLogin({
                email: user.email,
                password: user.password
            });

        }

        var data = await response;

        if (data) {
            sessionStorage.setItem("authUser", JSON.stringify(data.data));
            setAuthorization(data.data.token);
            dispatch(loginSuccess(data.data));
            history('/dashboard');
        }
    } catch (error) {
        dispatch(apiError(error));
    }
};

export const logoutUser = () => async (dispatch: any) => {
    try {
        await postApiLogout({});
    } catch (error: any) {
        // ignora erro de token inválido / sessão expirada no logout
        console.warn("Logout API failed, clearing local session anyway.", error);
    } finally {
        sessionStorage.removeItem("authUser");
        localStorage.removeItem("authUser");
        setAuthorization(null);
        dispatch(logoutUserSuccess(true));
    }
};

export const resetLoginFlag = () => async (dispatch: any) => {
    try {
        const response = dispatch(reset_login_flag());
        return response;
    } catch (error) {
        dispatch(apiError(error));
    }
};