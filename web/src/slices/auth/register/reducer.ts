
import { createSlice } from "@reduxjs/toolkit";
import { getUserByInvite } from "./thunk";

export const initialState = {
    userInvite: null,
    error: null as any,
    loading: false,
};

const RegisterSlice = createSlice({
    name: "register",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST INVITE
        builder
            .addCase(getUserByInvite.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(getUserByInvite.fulfilled, (state, action) => {
                state.loading = false;
                state.userInvite = action.payload.data;
            })
            .addCase(getUserByInvite.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload || action.error;
            });
    },
});

export default RegisterSlice.reducer;