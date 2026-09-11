import { ISupplierPayload } from "common/models/supplier.model";

export const SUPPLIER_CREATE_DEFAULTS: ISupplierPayload = {
    name: "",
    nif: null,
    phone: null,
    email: null,
    address: null,
    postal_code: null,
    district_id: null,
    municipality_id: null,
    parish_id: null,
    iban: null,
    notes: null,
};
