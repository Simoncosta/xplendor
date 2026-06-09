import { createAsyncThunk } from "@reduxjs/toolkit";
import { closeCarSale as closeCarSaleApi, updateCarSale as updateCarSaleApi } from "../../helpers/laravel_helper";

// Igual ao padrão do cars/thunk.ts — preserva 422 (com ou sem errors) para o
// catch do CarUpdate ler campo-a-campo e renderizar o ValidationAlert.
const preserveValidationOrFallback = (error: any) => {
    if (error && typeof error === "object" && (error.errors || error.message)) {
        return error;
    }
    return error?.response?.data || error?.message || error;
};

export const closeCarSale = createAsyncThunk(
    "carSale/closeCarSale",
    async ({ companyId, carId, formData }: { companyId: number; carId: number; formData: FormData }, { rejectWithValue }) => {
        try {
            return await closeCarSaleApi(companyId, carId, formData);
        } catch (error: any) {
            return rejectWithValue(preserveValidationOrFallback(error));
        }
    }
);

// PATCH dos dados PII do comprador num car_sale existente (ou cria se faltar).
// NÃO mexe no car nem dispara notificações. Pré-condição: car.status === 'sold'.
export const updateCarSale = createAsyncThunk(
    "carSale/updateCarSale",
    async ({ companyId, carId, data }: { companyId: number; carId: number; data: Record<string, unknown> }, { rejectWithValue }) => {
        try {
            return await updateCarSaleApi(companyId, carId, data);
        } catch (error: any) {
            return rejectWithValue(preserveValidationOrFallback(error));
        }
    }
);
