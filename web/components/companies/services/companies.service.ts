import { AxiosInstance } from "axios";
import { ICompany } from "../models/companies.model";
import api from "../../../services/axiosInstance";

export class CompanyService {
    private axios: AxiosInstance;

    constructor(axiosInstance: AxiosInstance,) {
        this.axios = axiosInstance;
    }

    createCompanyDefault = (): ICompany => ({
        id: 0,

        name_user: '',
        email_user: '',

        nipc: '',
        fiscal_name: '',
        trade_name: null,
        responsible_name: null,

        address: null,
        postal_code: null,
        district_id: null,
        municipality_id: null,
        parish_id: null,

        phone: null,
        mobile: null,
        email: null,
        invoice_email: null,

        capital_social: null,
        certification_number: null,
        registration_fees: 0,
        export_promotion_price: false,

        credit_intermediation_link: null,

        vat_value: null,
        facebook_page_id: null,
        facebook_pixel_id: null,
        facebook_access_token: null,

        lead_hours_pending: null,
        lead_distribution: 'manual',

        ad_text: null,

        pdf_path: null,
        logo_path: null,
        carmine_logo_path: null,

        plan_id: 1,

        created_at: '',
        updated_at: '',
        deleted_at: null,
    });

    async getCompanies(perPage: number | null) {
        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/companies`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/companies?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter empresas: " + (error?.message || error));
        }
    }

    async getCompany(id: number) {
        if (id == undefined) {
            throw new Error('O id é obrigatório')
        }

        try {
            const res = await this.axios.get(`/v1/companies/${id}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao obter empresas: " + (error?.message || error));
        }
    }

    async updateCompany(id: number, data: ICompany) {
        if (id == undefined) {
            throw new Error('O id é obrigatório')
        }

        try {
            const res = await this.axios.post(`/v1/companies/${id}?_method=PUT`, data);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao atualizar empresas: " + (error?.message || error));
        }
    }

    async saveCompany(data: ICompany) {
        try {
            const res = await this.axios.post(`/v1/companies`, data);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao salvar empresa: " + (error?.message || error));
        }
    }

    async removeCompany(id: number) {
        if (id == undefined) {
            throw new Error('O id é obrigatório')
        }

        try {
            const res = await this.axios.delete(`/v1/companies/${id}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao remover empresa: " + (error?.message || error));
        }
    }
}