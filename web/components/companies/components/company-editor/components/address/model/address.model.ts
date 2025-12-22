import { ICompany } from "@/components/companies/models/companies.model";
import { Control, UseFormRegister, UseFormSetValue } from "react-hook-form";

export interface AddressFieldsProps {
    register: UseFormRegister<any>;
    control: any;
    disabled?: boolean;
    setValue: UseFormSetValue<any>;
    watch: (field: string) => any;
}

export interface IDistrict {
    id: number | undefined;
    name: string;
}

export interface IMunicipality {
    id: number | undefined;
    name: string;
    district_id: number | undefined;
}

export interface IParish {
    id: number | undefined;
    name: string;
    municipality_id: number | undefined;
}