import { ICustomerPayload } from "common/models/customer.model";

export const CUSTOMER_CREATE_DEFAULTS: ICustomerPayload = {
    name: "",
    nif: null,
    phone: null,
    email: null,
    address: null,
    postal_code: null,
    district_id: null,
    municipality_id: null,
    parish_id: null,
    citizen_card_number: null,
    citizen_card_validity: null,
    birth_date: null,
    nationality: null,
    profession: null,
    marital_status: null,
    contact_consent: false,
    notes: null,
};
