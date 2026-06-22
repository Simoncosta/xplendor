// React
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useDispatch, useSelector } from "react-redux";
import { toast, ToastContainer } from "react-toastify";
import { createSelector } from "reselect";
// Slices
import { CAR_CREATE_DEFAULTS } from "slices/cars/car.defaults";
import { createCar } from "slices/cars/thunk";
// Components
import CarEditor from "./CarEditor";
// Utils
import { buildCarFormData } from "./utils/buildCarFormData";
import { showApiErrorToast, parseApiValidationErrors, type ApiValidationError } from "helpers/error_helper";

const selectCarState = (state: any) => state.Car;

const selectCarCreateViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        loadingCreate: carState.loading.create,
    })
);

export default function CarCreate() {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();

    document.title = "Novo Carro | Xplendor";

    // States
    const [companyId, setCompanyId] = useState(0);
    const [validationErrors, setValidationErrors] = useState<ApiValidationError[] | null>(null);
    const [draftLoading, setDraftLoading] = useState(false);
    const { loadingCreate } = useSelector(selectCarCreateViewModel);

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
            <CarEditor
                data={CAR_CREATE_DEFAULTS}
                loading={loadingCreate}
                draftLoading={draftLoading}
                companyId={companyId}
                validationErrors={validationErrors}
                onDismissValidationErrors={() => setValidationErrors(null)}
                onSubmit={async (values: any) => {
                    if (loadingCreate) return;

                    setValidationErrors(null);
                    const fd = buildCarFormData(values, { isUpdate: false });

                    try {
                        await dispatch(createCar({ companyId, formData: fd })).unwrap();
                        toast("Carro criado com sucesso!", { position: "top-right", hideProgressBar: false, className: "bg-success text-white" });
                        navigate(-1);
                    } catch (error) {
                        setValidationErrors(parseApiValidationErrors(error));
                        showApiErrorToast(error, "Erro ao criar viatura.");
                    }
                }}
                onSubmitDraft={async (values: any) => {
                    if (draftLoading || loadingCreate) return;

                    setValidationErrors(null);
                    setDraftLoading(true);
                    const fd = buildCarFormData(values, { isUpdate: false });
                    // R2 (1.14.7) — flag dispara `CarRequest::isDraftSave()` no BE.
                    fd.append("__save_as_draft", "1");

                    try {
                        const created: any = await dispatch(createCar({ companyId, formData: fd })).unwrap();
                        toast("Rascunho guardado. Podes continuar mais tarde.", { position: "top-right", hideProgressBar: false, className: "bg-success text-white" });
                        // Redirecciona para /edit para a Matilde continuar onde parou.
                        const newId = created?.data?.id ?? created?.id;
                        if (newId) {
                            navigate(`/cars/${newId}/edit`, { replace: true });
                        }
                    } catch (error) {
                        setValidationErrors(parseApiValidationErrors(error));
                        showApiErrorToast(error, "Erro ao guardar rascunho.");
                    } finally {
                        setDraftLoading(false);
                    }
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}
