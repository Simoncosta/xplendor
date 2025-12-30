import { AxiosInstance } from "axios";
import { ICar } from "../models/cars.model";
import api from "../../../services/axiosInstance";

export class CarService {
    private axios: AxiosInstance;
    private companyId: number;

    constructor(axiosInstance: AxiosInstance, companyId: number) {
        this.axios = axiosInstance;
        this.companyId = companyId;
    }

    createCarDefault = (): ICar => ({
        id: undefined,
        status: 'draft',
        origin: 'national',
        license_plate: '',
        vin: '',
        registration_month: null,
        registration_year: new Date().getFullYear(),

        car_brand_id: 0,
        car_model_id: 0,
        version: '',
        public_version_name: '',
        fuel_type: '',
        power_hp: 0,
        engine_capacity_cc: 0,
        doors: 4,
        transmission: '',

        segment: '',
        seats: 5,
        exterior_color: '',
        is_metallic: false,
        interior_color: '',
        condition: 'used',
        mileage_km: null,

        co2_emissions: null,
        toll_class: '',
        cylinders: null,
        warranty_available: '',
        warranty_due_date: '',
        warranty_km: null,
        service_records: '',
        has_spare_key: false,
        has_manuals: false,

        price_gross: null,
        price_net: null,
        hide_price_online: false,
        monthly_payment: null,

        extras: [],
        lifestyle: [],

        description_website_pt: '',
        description_website_en: '',
        internal_notes: '',
        youtube_url: '',

        images: [],
        images_meta: [],
        exterior_360_images: [],
        exterior_360_meta: [],

        company_id: this.companyId,
    });

    async getCars(perPage: number | null) {
        try {
            if (!perPage) {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/cars`);
                return res.data.data;
            } else {
                const res = await this.axios.get(`/v1/companies/${this.companyId}/cars?perPage=${perPage}`);
                return res.data.data;
            }
        } catch (error: any) {
            throw new Error("Erro ao obter carros: " + (error?.message || error));
        }
    }

    async getCar(id: number) {
        if (id == undefined) {
            throw new Error('O id é obrigatório')
        }

        try {
            const res = await this.axios.get(`/v1/companies/${this.companyId}/cars/${id}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao obter carros: " + (error?.message || error));
        }
    }

    async updateCar(id: number, values: ICar) {
        if (!id) throw new Error("O ID é obrigatório");

        const formData = new FormData();

        /**
         * Helpers
         */
        const appendIf = (key: string, value: any) => {
            if (value === null || value === undefined) return;
            formData.append(key, value);
        };

        const toBool = (v: any) => (v ? "1" : "0");

        const toNumberString = (v: any) => {
            if (v === null || v === undefined || v === "") return null;
            const n = Number(String(v).replace(",", "."));
            return isNaN(n) ? null : String(n);
        };

        const toIntString = (v: any) => {
            if (v === null || v === undefined || v === "") return null;
            const n = parseInt(String(v), 10);
            return isNaN(n) ? null : String(n);
        };

        /**
         * 1) Campos simples com casting correto
         */
        const boolFields = ["is_metallic", "has_spare_key", "has_manuals", "hide_price_online"];
        const intFields = ["co2_emissions", "cylinders", "warranty_km", "power_hp", "engine_capacity_cc", "doors", "seats"];
        const floatFields = ["price_net", "price_gross", "monthly_payment", "mileage_km"];

        Object.entries(values).forEach(([key, value]) => {
            if (["images", "images_meta", "extras", "lifestyle", "exterior_360_images", "exterior_360_meta"].includes(key)) return;

            if (boolFields.includes(key)) {
                appendIf(key, toBool(value));
                return;
            }

            if (intFields.includes(key)) {
                const v = toIntString(value);
                if (v !== null) appendIf(key, v);
                return;
            }

            if (floatFields.includes(key)) {
                const v = toNumberString(value);
                if (v !== null) appendIf(key, v);
                return;
            }

            // Datas: manda como string mesmo (YYYY-MM-DD)
            // Strings: manda normal
            if (value !== null && value !== undefined) {
                appendIf(key, String(value));
            }
        });

        /**
         * 2) Extras e Lifestyle (manda como JSON — simples e sem sofrimento)
         */
        values.extras?.forEach((group, gIndex) => {
            formData.append(`extras[${gIndex}][group]`, group.group);

            (group.items || []).forEach((item: any, iIndex: any) => {
                formData.append(`extras[${gIndex}][items][${iIndex}]`, item);
            });
        });

        if (values.lifestyle) {
            appendIf("lifestyle", JSON.stringify(values.lifestyle));
        }

        /**
         * 3) Images (update aceita string + file no mesmo array images[])
         * Mantém o mesmo index para imagens + meta.
         */
        const images = values.images || [];
        const meta = values.images_meta || [];

        images.forEach((img: any, index: number) => {
            // imagens existentes
            if (typeof img === "string") {
                formData.append(`existing_images[${index}]`, img);
            } else if (typeof img?.image === "string") {
                formData.append(`existing_images[${index}]`, img.image);
            }

            // imagens novas
            if (img?.file instanceof File) {
                formData.append(`images[${index}]`, img.file);
            }

            const m = meta[index] || {};
            formData.append(`images_meta[${index}][order]`, String(m.order ?? index + 1));
            formData.append(`images_meta[${index}][is_primary]`, m.is_primary ? "1" : "0");
        });

        /**
         * 4) Exterior 360 (mesma lógica)
         */
        const exterior360 = values.exterior_360_images || [];
        const exteriorMeta = values.exterior_360_meta || [];

        exterior360.forEach((img: any, index: number) => {
            if (typeof img === "string") {
                formData.append(`exterior_360_images[${index}]`, img);
            } else if (typeof img?.image === "string") {
                formData.append(`exterior_360_images[${index}]`, img.image);
            } else if (img?.file instanceof File) {
                formData.append(`exterior_360_images[${index}]`, img.file);
            }

            const m = exteriorMeta[index] || {};
            formData.append(`exterior_360_meta[${index}][order]`, String(m.order ?? index + 1));
        });

        /**
         * DEBUG: ver exatamente o que estás mandando
         */
        // for (const pair of formData.entries()) console.log(pair[0], pair[1]);

        /**
         * Request
         */
        try {
            const res = await this.axios.post(
                `/v1/companies/${this.companyId}/cars/${id}?_method=PUT`,
                formData,
                { headers: { "Content-Type": "multipart/form-data" } }
            );

            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao atualizar o carro: " + (error?.message || error));
        }
    }

    async saveCar(values: ICar) {
        const formData = new FormData();

        // Campos simples (exclui os arrays que serão tratados à parte)
        Object.entries(values).forEach(([key, value]) => {
            if (['images', 'images_meta', 'extras', 'lifestyle', 'exterior_360_images', 'exterior_360_meta'].includes(key)) return;
            if (value === null || value === undefined) return;

            // Conversão de boolean explícita para o backend entender no FormData
            if (typeof value === 'boolean') {
                formData.append(key, value ? "1" : "0");
            } else if (typeof value === 'object') {
                formData.append(key, JSON.stringify(value));
            } else {
                formData.append(key, String(value));
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Imagens normais
        |--------------------------------------------------------------------------
        */
        const images = values.images || [];
        const imagesMeta = values.images_meta || [];

        images.forEach((img: any, index: number) => {
            // ✅ No STORE só deve aceitar File
            if (img?.file instanceof File) {
                formData.append(`images[${index}]`, img.file);
            }
        });

        imagesMeta.forEach((m: any, index: number) => {
            formData.append(`images_meta[${index}][order]`, m?.order ?? index + 1);
            formData.append(`images_meta[${index}][is_primary]`, m?.is_primary ? "1" : "0");
        });

        /*
        |--------------------------------------------------------------------------
        | Extras
        |--------------------------------------------------------------------------
        */
        values.extras?.forEach((group, groupIndex) => {
            formData.append(`extras[${groupIndex}][group]`, group.group);

            (group.items || []).forEach((item: string, itemIndex: number) => {
                formData.append(`extras[${groupIndex}][items][${itemIndex}]`, item);
            });
        });

        /*
        |--------------------------------------------------------------------------
        | Lifestyle
        |--------------------------------------------------------------------------
        */
        values.lifestyle?.forEach((item, i) => {
            formData.append(`lifestyle[${i}]`, item);
        });

        /*
        |--------------------------------------------------------------------------
        | Imagens 360 exterior (se aplicares no store também)
        |--------------------------------------------------------------------------
        */
        const exterior360 = values.exterior_360_images || [];
        const exterior360Meta = values.exterior_360_meta || [];

        exterior360.forEach((img: any, index: number) => {
            if (img?.file instanceof File) {
                formData.append(`exterior_360_images[${index}]`, img.file);
            }
        });

        exterior360Meta.forEach((m: any, index: number) => {
            formData.append(`exterior_360_meta[${index}][order]`, m?.order ?? index + 1);
        });

        try {
            const res = await this.axios.post(
                `/v1/companies/${this.companyId}/cars`,
                formData,
                { headers: { 'Content-Type': 'multipart/form-data' } }
            );

            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao salvar carro: " + (error?.message || error));
        }
    }

    async removeCar(id: number) {
        if (id == undefined) {
            throw new Error('O id é obrigatório')
        }

        try {
            const res = await this.axios.delete(`/v1/companies/${this.companyId}/cars/${id}`);
            return res.data.data;
        } catch (error: any) {
            throw new Error("Erro ao remover empresa: " + (error?.message || error));
        }
    }
}