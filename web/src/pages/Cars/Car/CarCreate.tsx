// React
import { useNavigate } from "react-router-dom";
import { useDispatch } from "react-redux";
// Slices
import { COMPANY_CREATE_DEFAULTS } from "slices/companies/company.defaults";
import { createCompany } from "slices/thunks";
// Components
import CompanyProfileEditor from "./CarEditor";
import { toast, ToastContainer } from "react-toastify";

export default function CarCreate() {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();

    document.title = "Novo Carro | Xplendor";

    return (
        <>
            <ToastContainer />
            <CompanyProfileEditor
                data={COMPANY_CREATE_DEFAULTS}
                onSubmit={(values: any) => {
                    const formData = new FormData();

                    Object.entries(values).forEach(([key, value]: any) => {
                        if (value === null || value === undefined) return;

                        if (key === "logo_file" && value instanceof File) {
                            formData.append("logo", value);
                        } else if (typeof value === "object" && !(value instanceof File)) {
                            formData.append(key, JSON.stringify(value));
                        } else {
                            formData.append(key, String(value));
                        }
                    });

                    toast("Carro criado com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                    dispatch(createCompany(formData));
                    navigate(-1);
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}