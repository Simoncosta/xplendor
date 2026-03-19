import { createSlice } from "@reduxjs/toolkit";
import {
    createUser,
    getUsersPaginate,
    showUser,
    updateUser,
} from "./thunk";

const initialState = {
    data: {
        users: [] as any[],
        meta: null as any,
        user: null as any | null,
    },
    loading: {
        list: false,
        show: false,
        create: false,
        update: false,
    },
    error: {
        list: null as any,
        show: null as any,
        create: null as any,
        update: null as any,
    },
};

const UserSlice = createSlice({
    name: "user",
    initialState,
    reducers: {},
    extraReducers: (builder) => {
        // LIST
        builder
            .addCase(getUsersPaginate.pending, (state) => {
                state.loading.list = true;
                state.error.list = null;
            })
            .addCase(getUsersPaginate.fulfilled, (state, action) => {
                state.loading.list = false;
                state.error.list = null;
                state.data.users = action.payload.data.data;
                state.data.meta = action.payload.data;
            })
            .addCase(getUsersPaginate.rejected, (state, action) => {
                state.loading.list = false;
                state.error.list = action.payload || action.error;
            });

        // SHOW
        builder
            .addCase(showUser.pending, (state) => {
                state.loading.show = true;
                state.error.show = null;
            })
            .addCase(showUser.fulfilled, (state, action) => {
                state.loading.show = false;
                state.error.show = null;
                state.data.user = action.payload.data;
            })
            .addCase(showUser.rejected, (state, action) => {
                state.loading.show = false;
                state.error.show = action.payload || action.error;
            });

        // CREATE
        builder
            .addCase(createUser.pending, (state) => {
                state.loading.create = true;
                state.error.create = null;
            })
            .addCase(createUser.fulfilled, (state, action) => {
                state.loading.create = false;
                state.error.create = null;
                state.data.user = action.payload.data;
            })
            .addCase(createUser.rejected, (state, action) => {
                state.loading.create = false;
                state.error.create = action.payload || action.error;
            });

        // UPDATE
        builder
            .addCase(updateUser.pending, (state) => {
                state.loading.update = true;
                state.error.update = null;
            })
            .addCase(updateUser.fulfilled, (state, action) => {
                state.loading.update = false;
                state.error.update = null;

                const updatedUser = action.payload.data.user;

                // atualiza o user que está a ser editado
                state.data.user = updatedUser;

                // pega authUser da session
                const authUserRaw = sessionStorage.getItem("authUser");
                const authUser = authUserRaw ? JSON.parse(authUserRaw) : null;

                // verifica se é o utilizador autenticado
                if (authUser?.id === updatedUser.id) {
                    const mergedUser = {
                        ...authUser,
                        ...updatedUser,
                    };

                    state.data.user = mergedUser;

                    sessionStorage.setItem("authUser", JSON.stringify(mergedUser));
                }
            })
            .addCase(updateUser.rejected, (state, action) => {
                state.loading.update = false;
                state.error.update = action.payload || action.error;
            });
    },
});

export default UserSlice.reducer;
