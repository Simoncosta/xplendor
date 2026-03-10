import { createSlice } from "@reduxjs/toolkit";
import {
    getUsersPaginate,
    showUser,
    updateUser,
} from "./thunk";

const initialState = {
    users: [] as any[],
    meta: null as any,

    user: null as any | null,

    loadingList: false,
    loadingShow: false,
    loadingUpdate: false,

    errorList: null as any,
    errorShow: null as any,
    errorUpdate: null as any,
};

const UserSlice = createSlice({
    name: "user",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getUsersPaginate.pending, (state) => {
                state.loadingList = true;
                state.errorList = null;
            })
            .addCase(getUsersPaginate.fulfilled, (state, action) => {
                state.loadingList = false;
                state.users = action.payload.data.data;
                state.meta = action.payload.data;
            })
            .addCase(getUsersPaginate.rejected, (state, action) => {
                state.loadingList = false;
                state.errorList = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showUser.pending, (state) => {
                state.loadingShow = true;
                state.errorShow = null;
            })
            .addCase(showUser.fulfilled, (state, action) => {
                state.loadingShow = false;
                state.user = action.payload.data;
            })
            .addCase(showUser.rejected, (state, action) => {
                state.loadingShow = false;
                state.errorShow = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateUser.pending, (state) => {
                state.loadingUpdate = true;
                state.errorUpdate = null;
            })
            .addCase(updateUser.fulfilled, (state, action) => {
                state.loadingUpdate = false;

                const updatedUser = action.payload.data.user;

                // atualiza o user que está a ser editado
                state.user = updatedUser;

                // pega authUser da session
                const authUserRaw = sessionStorage.getItem("authUser");
                const authUser = authUserRaw ? JSON.parse(authUserRaw) : null;

                // verifica se é o utilizador autenticado
                if (authUser?.id === updatedUser.id) {
                    const mergedUser = {
                        ...authUser,
                        ...updatedUser,
                    };

                    state.user = mergedUser;

                    sessionStorage.setItem("authUser", JSON.stringify(mergedUser));
                }
            })
            .addCase(updateUser.rejected, (state, action) => {
                state.loadingUpdate = false;
                state.errorUpdate = action.payload || action.error;
            });
    },
});

export default UserSlice.reducer;