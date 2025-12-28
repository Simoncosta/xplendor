import { UseFormRegister, UseFormSetValue } from "react-hook-form";

export interface CarDetailsFieldsProps {
    register: UseFormRegister<any>;
    control: any;
    disabled?: boolean;
    setValue: UseFormSetValue<any>;
    watch: (field: string) => any;
}
