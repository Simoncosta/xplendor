export type CarSaleBuyerGender = "male" | "female" | "company";
export type CarSaleBuyerAgeRange = "18-30" | "31-45" | "46-60" | "60+";
export type CarSaleChannel = "online" | "in_person" | "referral" | "trade_in";

export interface ICarSalePayload {
    sale_price: number | null;
    buyer_gender: CarSaleBuyerGender | "";
    buyer_age_range: CarSaleBuyerAgeRange | "";
    sale_channel: CarSaleChannel | "";
    buyer_name: string;
    buyer_phone: string;
    buyer_email: string;
    contact_consent: boolean;
    notes: string;
}
