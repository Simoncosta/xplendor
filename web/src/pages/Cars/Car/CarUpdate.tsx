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

export default function CarUpdate() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Editar Carro | Xplendor";

    // State
    const [companyId, setCompanyId] = useState<number>(0);

    const selectCarState = (state: any) => state.Car;

    const carSelector = createSelector(selectCarState, (state: any) => ({
        car: state.data.car,
        loading: state.loading.show,
    }));

    const { car, loading } = useSelector(carSelector);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
            dispatch(showCar({ companyId: obj.company_id, id: Number(id) }));
        }
    }, [dispatch, id]);

    if (loading) return null;

    return (
        <>
            <ToastContainer />
            <CompanyProfileEditor
                data={car ?? CAR_CREATE_DEFAULTS}
                onSubmit={(values: any) => {
                    const fd = buildCarFormData(values);
                    fd.append("_method", "PUT");

                    dispatch(updateCar({ companyId: companyId, id: Number(id), formData: fd }));
                    toast("Carro atualizado com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}
