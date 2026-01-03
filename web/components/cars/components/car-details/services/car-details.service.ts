import { AxiosInstance } from "axios";
import { ICar } from "../../../models/cars.model";

export class CarDetailsService {
    private axios: AxiosInstance;
    private companyId: number;
    private carId: number;

    constructor(axiosInstance: AxiosInstance, companyId: number, carId: number) {
        this.axios = axiosInstance;
        this.companyId = companyId;
        this.carId = carId;
    }

    async generateAiAnalyses() {
        try {
            const res = await this.axios.post(`/v1/companies/${this.companyId}/car-ai-analyses/${this.carId}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao obter carros: " + (error?.message || error));
        }
    }

    async feedbackAiAnalyses(carAiAnalysisId: number, feedback: string) {
        if (!carAiAnalysisId) throw new Error("O ID é obrigatório");
        if (!feedback) throw new Error("O feedback é obrigatório");

        try {
            const res = await this.axios.put(`/v1/companies/${this.companyId}/car-ai-analyses-feedback/${carAiAnalysisId}`, {
                feedback,
            });
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao obter carros: " + (error?.message || error));
        }
    }
}