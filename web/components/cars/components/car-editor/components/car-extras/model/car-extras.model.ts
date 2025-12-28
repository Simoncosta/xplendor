import { ICar } from "@/components/cars/models/cars.model";
import { Control, UseFormRegister, UseFormSetValue } from "react-hook-form";

export interface CarExtrasFieldsProps {
    register: UseFormRegister<any>;
    control: any;
    disabled?: boolean;
    setValue: UseFormSetValue<any>;
    watch: (field: string) => any;
}
