// React
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
// Redux
import { useDispatch, useSelector } from "react-redux";
import { createSelector } from "reselect";
// Slices
import { USER_CREATE_DEFAULTS } from "slices/users/user.defaults";
import { createUser } from "slices/thunks";
// Components
import UserEditor from "./UserEditor";
import { toast, ToastContainer } from "react-toastify";

const selectUserState = (state: any) => state.User;

const selectUserCreateViewModel = createSelector(
    [selectUserState],
    (userState: any) => ({
        loadingCreate: userState.loading.create,
    })
);

export default function UserCreate() {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();

    document.title = "Novo Colaborador | Xplendor";

    // States
    const [companyId, setCompanyId] = useState(0);
    const { loadingCreate } = useSelector(selectUserCreateViewModel);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
        }
    }, [dispatch]);

    return (
        <>
            <ToastContainer />
            <UserEditor
                data={USER_CREATE_DEFAULTS}
                loading={loadingCreate}
                onSubmit={(values) => {
                    const formData = new FormData();

                    const appendAvatar = (value: any) => {
                        if (!value) return;

                        if (value instanceof File) {
                            formData.append("avatar", value);
                            return;
                        }

                        // se vier FileList
                        if (value instanceof FileList && value.length > 0) {
                            formData.append("avatar", value[0]);
                            return;
                        }

                        // se vier array de files
                        if (Array.isArray(value) && value[0] instanceof File) {
                            formData.append("avatar", value[0]);
                            return;
                        }
                    };

                    Object.entries(values).forEach(([key, value]: any) => {
                        if (value === null || value === undefined) return;

                        if (key === "avatar") {
                            appendAvatar(value);
                            return;
                        }

                        if (typeof value === "object" && !(value instanceof File)) {
                            formData.append(key, JSON.stringify(value));
                            return;
                        }

                        formData.append(key, String(value));
                    });

                    dispatch(createUser({ companyId: Number(companyId), formData: formData }));
                    toast("Colaborador criado com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}
