import { configureStore, combineReducers } from '@reduxjs/toolkit';
import themeConfig from './themeConfigSlice';
import auth from './authSlice';

const rootReducer = combineReducers({
    themeConfig,
    auth,
});

export const store = configureStore({
    reducer: rootReducer,
});

export type IRootState = ReturnType<typeof rootReducer>;
export type AppDispatch = typeof store.dispatch;