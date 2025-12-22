import { AxiosInstance } from "axios";
import { IUsers } from "../models/user.model";

export class UsersService {
    private axios: AxiosInstance;
    private companyId: number;

    constructor(axiosInstance: AxiosInstance, companyId: number) {
        this.axios = axiosInstance;
        this.companyId = companyId;
    }

    createUserDefault(): IUsers {
        return {
            id: undefined,
            name: "",
            avatar: "",
            email: "",
            birthdate: "",
            gender: "",
            role: "user",
        }
    }

    async getUsers(perPage: number | null) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/users`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/users?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter frações: " + (error?.message || error));
        }
    }

    async getUser(id: number) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            const res = await this.axios.get(`/v1/companies/${this.companyId}/users/${id}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao obter o utilizador: " + (error?.message || error));
        }
    }

    async updateUser(id: number, data: IUsers) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            const res = await this.axios.post(`/v1/companies/${this.companyId}/users/${id}?_method=PUT`, data, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao atualizar o utilizador: " + (error?.message || error));
        }
    }

    async saveUser(data: IUsers) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            const res = await this.axios.post(`/v1/companies/${this.companyId}/users`, data, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao salvar o utilizador: " + (error?.message || error));
        }
    }
}