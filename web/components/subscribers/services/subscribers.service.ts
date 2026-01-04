import { AxiosInstance } from "axios";

export class SubscribersService {
    private axios: AxiosInstance;
    private companyId: number;

    constructor(axiosInstance: AxiosInstance, companyId: number) {
        this.axios = axiosInstance;
        this.companyId = companyId;
    }

    async getSubscribers(perPage: number | null) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/subscribers`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/subscribers?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter frações: " + (error?.message || error));
        }
    }
}