import { IUser } from "common/models/user.model";

export const USER_CREATE_DEFAULTS: IUser = {
    id: 0,
    name: "",
    email: "",
    password: "",
    role: "user",
    avatar: "",
    gender: 'male',
    birthdate: "",
}