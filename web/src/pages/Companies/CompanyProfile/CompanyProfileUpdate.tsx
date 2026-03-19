// React
import { useEffect } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useNavigate, useParams } from "react-router-dom";
import { createSelector } from "reselect";
import { toast, ToastContainer } from "react-toastify";
// Components
import CompanyProfileEditor from "./CompanyProfileEditor";
import {
    createCarmine,
    showCarmine,
    showCompany,
    updateCarmine,
    updateCompany
} from "slices/thunks";
// Slices
import { COMPANY_CREATE_DEFAULTS } from "slices/companies/company.defaults";
import { CARMINE_API_CREATE_DEFAULTS } from "slices/carmine/carmine-api.defaults";

const selectCompanyState = (state: any) => state.Company;
const selectCarmineState = (state: any) => state.Carmine;

const selectCompanyProfileViewModel = createSelector(
    [selectCompanyState],
    (companyState) => ({
        company: companyState.data.company,
        loadingShow: companyState.loading.show,
    })
);

const selectCarmineProfileViewModel = createSelector(
    [selectCarmineState],
    (carmineState) => ({
        carmine: carmineState.data.carmine,
        loading: carmineState.loading.show,
    })
);

export default function CompanyProfileUpdate() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Perfil da Empresa | Xplendor";

    const { company, loadingShow } = useSelector(selectCompanyProfileViewModel);
    const { carmine, loading } = useSelector(selectCarmineProfileViewModel);

    useEffect(() => {
        dispatch(showCompany(Number(id)));
        dispatch(showCarmine({ companyId: Number(id), id: 0 }));
    }, [dispatch, id]);

    if (loadingShow) return null;

    return (
        <>
            <ToastContainer />
            <CompanyProfileEditor
                data={company ?? COMPANY_CREATE_DEFAULTS}
                dataCarmine={carmine ?? CARMINE_API_CREATE_DEFAULTS}
                onSubmit={(values) => {
                    const formData = new FormData();

                    const appendLogo = (value: any) => {
                        if (!value) return;

                        if (value instanceof File) {
                            formData.append("logo", value);
                            return;
                        }

                        // se vier FileList
                        if (value instanceof FileList && value.length > 0) {
                            formData.append("logo", value[0]);
                            return;
                        }

                        // se vier array de files
                        if (Array.isArray(value) && value[0] instanceof File) {
                            formData.append("logo", value[0]);
                            return;
                        }
                    };

                    Object.entries(values).forEach(([key, value]: any) => {
                        if (value === null || value === undefined) return;

                        if (key === "logo_file") {
                            appendLogo(value);
                            return;
                        }

                        if (typeof value === "object" && !(value instanceof File)) {
                            formData.append(key, JSON.stringify(value));
                            return;
                        }

                        formData.append(key, String(value));
                    });

                    formData.append("_method", "PUT");

                    dispatch(updateCompany({ id: Number(id), formData: formData }));
                    toast("Empresa atualizada com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                }}
                onSubmitCarmine={(value) => {

                    if (!value.id) {
                        dispatch(createCarmine({ companyId: Number(id), data: value }));
                        toast("API Carmine criada com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                    } else {
                        dispatch(updateCarmine({ companyId: Number(id), id: value.id, data: value }));
                        toast("API Carmine atualizada com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                    }
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}
