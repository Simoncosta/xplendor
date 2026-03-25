// React
import { useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useNavigate, useParams } from "react-router-dom";
import { createSelector } from "reselect";
import { toast, ToastContainer } from "react-toastify";
// Components
import CompanyProfileEditor from "./CarEditor";
// Slices
import { CAR_CREATE_DEFAULTS } from "slices/cars/car.defaults";
import { showCar, updateCar } from "slices/cars/thunk";
// Utils
import { buildCarFormData } from "./utils/buildCarFormData";

const selectCarState = (state: any) => state.Car;

const selectCarUpdateViewModel = createSelector(
    [selectCarState],
    (carState) => ({
        car: carState.data.car,
        loadingShow: carState.loading.show,
        loadingUpdate: carState.loading.update,
    })
);

export default function CarUpdate() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Editar Carro | Xplendor";

    // State
    const [companyId, setCompanyId] = useState<number>(0);

    const { car, loadingShow, loadingUpdate } = useSelector(selectCarUpdateViewModel);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
            dispatch(showCar({ companyId: obj.company_id, id: Number(id) }));
        }
    }, [dispatch, id]);

    if (loadingShow) return null;

    return (
        <>
            <ToastContainer />
            <CompanyProfileEditor
                data={car ?? CAR_CREATE_DEFAULTS}
                loading={loadingUpdate}
                onSubmit={async (values: any) => {
                    if (loadingUpdate) return;

                    const fd = buildCarFormData(values);
                    fd.append("_method", "PUT");

                    try {
                        await dispatch(updateCar({ companyId: companyId, id: Number(id), formData: fd })).unwrap();
                        toast("Carro atualizado com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                    } catch (error) {
                        // Mantém o comportamento atual de erro sem toast de sucesso indevido.
                    }
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}
