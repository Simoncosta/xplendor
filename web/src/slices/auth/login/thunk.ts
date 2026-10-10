//Include Both Helper File with needed methods
import { setAuthorization } from "helpers/api_helper";
import {
    postApiLogin,
    postApiLogout,
    postRegisterByInvite as postRegisterByInviteApi,
} from "../../../helpers/laravel_helper";

import { loginSuccess, logoutUserSuccess, apiError, reset_login_flag } from './reducer';
import { clearTeamMarker, storeTeamMarker } from "helpers/teamMarker";
import { clearWorkingCompany } from "helpers/workingCompany";
import { isRootRole } from "helpers/roles";

export const registerByInvite =
    (payload: { token: string; password: string; password_confirmation: string }, navigate: any) =>
        async (dispatch: any) => {
            try {
                dispatch(reset_login_flag());
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
        dispatch(reset_login_flag());
        let response;

        if (process.env.REACT_APP_DEFAULTAUTH === "jwt") {
            response = postApiLogin({
                email: user.email,
                password: user.password
            });

        }

        var data = await response;

        if (data) {
            // Root: a marca da equipa vai para o localStorage (e não fica no authUser).
            const { team_marker, ...authUser } = data.data ?? {};
            storeTeamMarker(team_marker);
            data.data = authUser;
            sessionStorage.setItem("authUser", JSON.stringify(data.data));
            setAuthorization(data.data.token);
            dispatch(loginSuccess(data.data));
            // Uma página pediu para voltar depois de entrar (ex.: a página do pedido de gestão).
            let next: string | null = null;
            try { next = sessionStorage.getItem("xp-after-login"); sessionStorage.removeItem("xp-after-login"); } catch { /* ignore */ }
            history(next && next.startsWith("/") ? next : '/dashboard');
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
        // O root termina a sessão: o browser deixa de ser marcado como da equipa.
        try {
            const current = JSON.parse(sessionStorage.getItem("authUser") || "null");
            if (isRootRole(current?.role) || sessionStorage.getItem("rootAuthUser")) clearTeamMarker();
        } catch { /* sessão ilegível */ }
        sessionStorage.removeItem("authUser");
        localStorage.removeItem("authUser");
        clearWorkingCompany();
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
