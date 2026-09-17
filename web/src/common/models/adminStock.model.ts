// XPLENDOR — Stock GLOBAL (área /admin, só root). Veículos de todas as empresas
// ATIVAS. Vista transversal — cada veículo indica a empresa a que pertence.
export type CarStatus = "draft" | "active" | "inactive" | "sold" | "available_soon" | "reserved";

export interface IAdminStockCar {
    id: number;
    company_id: number;
    company_name: string | null;
    brand: string | null;
    model: string | null;
    version: string | null;
    vehicle_type: string | null;
    status: CarStatus;
    registration_year: number | null;
    mileage_km: number | null;
    price_gross: number | null;
    promo_price_gross: number | null;
    hide_price_online: boolean;
    thumbnail: string | null;
    created_at?: string;
}

export interface IAdminStockPage {
    data: IAdminStockCar[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface IAdminStockSummary {
    total_vehicles: number;
    active_companies: number;
    by_status: Record<string, number>;
}

export interface IAdminStockCompany {
    id: number;
    name: string;
    count: number;
}

// Rótulos + cor (Velzon) por status do veículo.
export const CAR_STATUS_META: Record<CarStatus, { label: string; color: string }> = {
    active:         { label: "Ativa",       color: "success" },
    available_soon: { label: "Brevemente",  color: "info" },
    reserved:       { label: "Reservada",   color: "warning" },
    sold:           { label: "Vendida",     color: "secondary" },
    draft:          { label: "Rascunho",    color: "light" },
    inactive:       { label: "Inativa",     color: "danger" },
};

export const CAR_STATUS_OPTIONS: CarStatus[] = ["active", "available_soon", "reserved", "sold", "draft", "inactive"];

/** Formata euros pt-PT. */
export const formatStockEuro = (v: number | null): string =>
    v == null ? "—" : new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(v);
