export type EnumUserRole = "admin" | "user" | "root";
export type EnumGender = "male" | "female";

export interface IUser {
    id: number | undefined;
    name: string;
    avatar?: string;
    signature?: string;
    email: string;
    gender?: EnumGender;
    birthdate?: string;
    mobile?: string;
    whatsapp?: string;
    password: string;
    role: EnumUserRole;
}
