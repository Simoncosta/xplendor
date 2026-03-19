import { createSlice } from "@reduxjs/toolkit";

export const initialState = {
    data: {
        user: {},
        success: "",
    },
    loading: {
        update: false,
    },
    error: {
        update: "",
    },
};

const ProfileSlice = createSlice({
    name: "Profile",
    initialState,
    reducers: {
        profileSuccess(state, action) {
            state.loading.update = false;
            state.error.update = "";
            state.data.success = action.payload.status;
            state.data.user = action.payload.data;
        },
        profileError(state, action) {
            state.loading.update = false;
            state.error.update = action.payload;
        },
        editProfileChange(state) {
            state = { ...state };
        },
        resetProfileFlagChange(state: any) {
            state.data.success = null;
        }
    },
});

export const {
    profileSuccess,
    profileError,
    editProfileChange,
    resetProfileFlagChange
} = ProfileSlice.actions

export default ProfileSlice.reducer;
