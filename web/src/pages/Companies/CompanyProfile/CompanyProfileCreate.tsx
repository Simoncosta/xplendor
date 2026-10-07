// React
import { useNavigate } from "react-router-dom";
import { useDispatch } from "react-redux";
// Slices
import { COMPANY_CREATE_DEFAULTS } from "slices/companies/company.defaults";
import { createCompany } from "slices/thunks";
// Components
import CompanyProfileEditor from "./CompanyProfileEditor";
import { toast, ToastContainer } from "react-toastify";
import { CARMINE_API_CREATE_DEFAULTS } from "slices/carmine/carmine-api.defaults";
import { setAdminCompanyAgency } from "helpers/laravel_helper";

export default function CompanyProfileCreate() {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();

    document.title = "Nova Empresa | Xplendor";

    return (
        <>
            <ToastContainer />
            <CompanyProfileEditor
                data={COMPANY_CREATE_DEFAULTS}
                dataCarmine={CARMINE_API_CREATE_DEFAULTS}
                onSubmit={async (values) => {
                    const formData = new FormData();
                    const isAgency = !!(values as any).is_agency;

                    Object.entries(values).forEach(([key, value]: any) => {
                        if (value === null || value === undefined || key === "is_agency") return;

                        if (key === "logo_file" && value instanceof File) {
                            formData.append("logo", value);
                        } else if (typeof value === "object" && !(value instanceof File)) {
                            formData.append(key, JSON.stringify(value));
                        } else if (typeof value === "boolean") {
                            // Laravel `boolean` não aceita "true"/"false" — usar "1"/"0".
                            formData.append(key, value ? "1" : "0");
                        } else {
                            formData.append(key, String(value));
                        }
                    });

                    const result: any = await dispatch(createCompany(formData));
                    if (result?.meta?.requestStatus !== "fulfilled") {
                        const p = result?.payload;
                        const first = p?.errors ? (Object.values(p.errors).flat()[0] as string) : null;
                        toast.error(first || (typeof p === "string" ? p : p?.message) || "Não foi possível criar a empresa.");
                        return;
                    }
                    // "Esta empresa é uma agência": marca-se logo a seguir à criação (só o root).
                    const newId = Number(result?.payload?.data?.id || 0);
                    if (isAgency && newId) {
                        try { await setAdminCompanyAgency(newId, true); } catch { toast.error("A empresa foi criada, mas não foi possível marcá-la como agência."); }
                    }
                    toast("Empresa criada com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                    navigate(-1);
                }}
                onSubmitCarmine={(value) => {
                    // console.log(value)
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}