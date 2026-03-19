
import { createSlice } from "@reduxjs/toolkit";
import { getUserByInvite } from "./thunk";

export const initialState = {
    data: {
        userInvite: null,
    },
    loading: {
        show: false,
    },
    error: {
        show: null as any,
    },
};

const RegisterSlice = createSlice({
    name: "register",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST INVITE
        builder
            .addCase(getUserByInvite.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(getUserByInvite.fulfilled, (state, action) => {
                state.loading.show = false;
                state.error.show = null;
                state.data.userInvite = action.payload.data;
            })
            .addCase(getUserByInvite.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });
    },
});

export default RegisterSlice.reducer;
