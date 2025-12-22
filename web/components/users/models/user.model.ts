export interface IUsers {
    id: number | undefined;
    name: string;
    avatar?: string | File;
    email: string;
    birthdate: string;
    gender: string;
    role: "root" | "admin" | "user";
}