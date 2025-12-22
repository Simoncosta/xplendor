import { AxiosInstance } from "axios";
import { ICompany } from "@/components/companies/models/companies.model";

export class AddressService {
    private axios: AxiosInstance;

    constructor(axiosInstance: AxiosInstance) {
        this.axios = axiosInstance;
    }

    async getDistricts(perPage: number | null) {
        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/districts`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/districts?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter distritos: " + (error?.message || error));
        }
    }

    async getMunicipalities(districtId: number, perPage: number | null) {
        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/districts/${districtId}/municipalities`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/districts/${districtId}/municipalities?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter municipios: " + (error?.message || error));
        }
    }

    async getParishes(municipalityId: number, perPage: number | null) {
        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/municipalities/${municipalityId}/parishes`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/municipalities/${municipalityId}/parishes?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter freguesias: " + (error?.message || error));
        }
    }
}