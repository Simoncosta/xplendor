// React
import { useEffect, useState } from "react";
import { createSelector } from "reselect";
// Redux
import { useDispatch, useSelector } from "react-redux";
import { useNavigate, useParams } from "react-router-dom";
// Components
import UserEditor from "./UserEditor";
// Slices
import { USER_CREATE_DEFAULTS } from "slices/users/user.defaults";
import { showUser, updateUser } from "slices/thunks";
// Utils
import { toast, ToastContainer } from "react-toastify";

export default function UserUpdate() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Editar Colaborador | Xplendor";

    // State
    const [companyId, setCompanyId] = useState<number>(0);

    const selectUserState = (state: any) => state.User;

    const userSelector = createSelector(selectUserState, (state: any) => ({
        user: state.user,
        loadingShow: state.loadingShow,
    }));

    const { user, loadingShow } = useSelector(userSelector);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
            dispatch(showUser({ companyId: obj.company_id, id: Number(id) }));
        }
    }, [dispatch, id]);

    if (loadingShow) return null;

    return (
        <>
            <ToastContainer />
            <UserEditor
                data={user ?? USER_CREATE_DEFAULTS}
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

                    formData.append("_method", "PUT");
                    dispatch(updateUser({ companyId: Number(companyId), id: Number(id), formData: formData }));
                    toast("Colaborador atualizado com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                }}
                onCancel={() => {
                    // history.goBack();
                }}
            />
        </>
    );
}