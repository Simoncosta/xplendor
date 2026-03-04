// action
import { profileSuccess, profileError, resetProfileFlagChange } from "./reducer";

export const editProfile = (user: any) => async (dispatch: any) => {
    try {
        let response;

        const data = await response;

        if (data) {
            dispatch(profileSuccess(data));
        }

    } catch (error) {
        dispatch(profileError(error));
    }
};

export const resetProfileFlag = () => {
    try {
        const response = resetProfileFlagChange();
        return response;
    } catch (error) {
        return error;
    }
};