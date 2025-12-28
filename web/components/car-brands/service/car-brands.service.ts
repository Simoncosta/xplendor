import { AxiosInstance } from "axios";
import api from "../../../services/axiosInstance";

export class CarBrandService {
    private axios: AxiosInstance;

    constructor(axiosInstance: AxiosInstance) {
        this.axios = axiosInstance;
    }

    async getCarBrands(perPage: number | null) {
        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/car-brands`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/car-brands?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter carros: " + (error?.message || error));
        }
    }
}