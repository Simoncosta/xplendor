import { ICompany } from "@/components/companies/models/companies.model";
import { Control, UseFormRegister, UseFormSetValue } from "react-hook-form";

export interface FiscalFieldsProps {
    register: UseFormRegister<any>;
    control: any;
    disabled?: boolean;
    setValue: UseFormSetValue<any>;
    watch: (field: string) => any;
}
