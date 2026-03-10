export type EnumUserRole = "admin" | "user";
export type EnumGender = "male" | "female";

export interface IUser {
    id: number | undefined;
    name: string;
    avatar?: string;
    signature?: string;
    email: string;
    gender?: EnumGender;
    birthdate?: string;
    password: string;
    role: EnumUserRole;
}