import { AxiosInstance } from "axios";
import { IBlogs } from "../models/blog.model";

export class BlogsService {
    private axios: AxiosInstance;
    private companyId: number;

    constructor(axiosInstance: AxiosInstance, companyId: number) {
        this.axios = axiosInstance;
        this.companyId = companyId;
    }

    createBlogDefault(): IBlogs {
        return {
            id: undefined,
            status: "draft",
            title: "",
            subtitle: "",
            banner: "",
            content: "",
            excerpt: "",
            tags: [],
        }
    }

    async getBlogs(perPage: number | null) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/blogs`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/blogs?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter frações: " + (error?.message || error));
        }
    }

    async getBlog(id: number) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            const res = await this.axios.get(`/v1/companies/${this.companyId}/blogs/${id}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao obter frações: " + (error?.message || error));
        }
    }

    async store(data: IBlogs) {
        if (this.companyId === undefined || this.companyId === null) return [];

        const formData = new FormData();

        // Campos simples
        formData.append('title', data.title || '');
        formData.append('subtitle', data.subtitle || '');
        formData.append('excerpt', data.excerpt || '');
        formData.append('content', data.content || '');
        formData.append('category', data.category || '');
        formData.append('status', data.status || '');
        formData.append('published_at', data.published_at || '');
        formData.append('meta_title', data.meta_title || '');
        formData.append('meta_description', data.meta_description || '');
        formData.append('og_title', data.og_title || '');
        formData.append('og_description', data.og_description || '');
        formData.append('og_image', data.og_image || '');
        formData.append('read_time', data.read_time?.toString() || '1');

        // Arrays (tags[])
        if (data.tags && Array.isArray(data.tags)) {
            data.tags.forEach(tag => {
                formData.append('tags[]', tag);
            });
        }

        // Banner (file ou string)
        // @ts-ignore
        if (typeof data.banner !== 'string' && data.banner?.file) {
            // @ts-ignore
            formData.append('banner', data.banner.file);
        }

        try {
            const res = await this.axios.post(
                `/v1/companies/${this.companyId}/blogs`,
                formData,
                {
                    headers: { 'Content-Type': 'multipart/form-data' }
                }
            );
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao atualizar blog: " + (error?.message || error));
        }
    }

    async update(id: number, data: IBlogs) {
        if (this.companyId === undefined || this.companyId === null) return [];

        const formData = new FormData();

        // Campos simples
        formData.append('title', data.title || '');
        formData.append('subtitle', data.subtitle || '');
        formData.append('excerpt', data.excerpt || '');
        formData.append('content', data.content || '');
        formData.append('category', data.category || '');
        formData.append('status', data.status || '');
        formData.append('published_at', data.published_at || '');
        formData.append('meta_title', data.meta_title || '');
        formData.append('meta_description', data.meta_description || '');
        formData.append('og_title', data.og_title || '');
        formData.append('og_description', data.og_description || '');
        formData.append('og_image', data.og_image || '');
        formData.append('read_time', data.read_time?.toString() || '1');

        // Arrays (tags[])
        if (data.tags && Array.isArray(data.tags)) {
            data.tags.forEach(tag => {
                formData.append('tags[]', tag);
            });
        }

        // Banner (file ou string)
        // @ts-ignore
        if (typeof data.banner !== 'string' && data.banner?.file) {
            // @ts-ignore
            formData.append('banner', data.banner.file);
        }

        try {
            const res = await this.axios.post(
                `/v1/companies/${this.companyId}/blogs/${id}?_method=PUT`, // Emula PUT se necessário
                formData,
                {
                    headers: { 'Content-Type': 'multipart/form-data' }
                }
            );
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao atualizar blog: " + (error?.message || error));
        }
    }

    async delete(id: number) {
        if (this.companyId === undefined || this.companyId === null) return [];

        try {
            const res = await this.axios.delete(`/v1/companies/${this.companyId}/blogs/${id}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao excluir blog: " + (error?.message || error));
        }
    }
}