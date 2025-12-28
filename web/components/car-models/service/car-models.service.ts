import { AxiosInstance } from "axios";

interface IFilterCarModels {
    car_brand_id: number;
}

export class CarModelService {
    private axios: AxiosInstance;

    constructor(axiosInstance: AxiosInstance) {
        this.axios = axiosInstance;
    }

    async getCarModels(perPage: number | null, filter: IFilterCarModels) {
        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/car-models`, {
                    params: filter
                });
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/car-models?perPage=${perPage}`, {
                    params: filter
                });
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter carros: " + (error?.message || error));
        }
    }
}