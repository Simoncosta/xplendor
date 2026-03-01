// React
import { useEffect } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useNavigate, useParams } from "react-router-dom";
import { createSelector } from "reselect";
// Components
import CompanyProfileEditor from "./CompanyProfileEditor";
import { showCompany, updateCompany } from "slices/thunks";
// Slices
import { COMPANY_CREATE_DEFAULTS } from "slices/companies/company.defaults";
import { toast, ToastContainer } from "react-toastify";

export default function CompanyProfileUpdate() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Perfil da Empresa | Xplendor";

    const selectCompanyState = (state: any) => state.Company;

    const companySelector = createSelector(selectCompanyState, (state: any) => ({
        company: state.company,
        loadingShow: state.loadingShow,
    }));

    const { company, loadingShow } = useSelector(companySelector);

    useEffect(() => {
        dispatch(showCompany(Number(id)));
    }, [dispatch, id]);

    if (loadingShow) return null;

    return (
        <>
            <ToastContainer />
            <CompanyProfileEditor
                data={company ?? COMPANY_CREATE_DEFAULTS}
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
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}