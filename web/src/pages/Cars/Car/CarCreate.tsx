// React
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useDispatch } from "react-redux";
import { toast, ToastContainer } from "react-toastify";
// Slices
import { CAR_CREATE_DEFAULTS } from "slices/cars/car.defaults";
import { createCar } from "slices/cars/thunk";
// Components
import CarEditor from "./CarEditor";
// Utils
import { buildCarFormData } from "./utils/buildCarFormData";

export default function CarCreate() {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();

    document.title = "Novo Carro | Xplendor";

    // States
    const [companyId, setCompanyId] = useState(0);

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
                onSubmit={(values: any) => {
                    const fd = buildCarFormData(values, { isUpdate: false });

                    dispatch(createCar({ companyId, formData: fd }));
                    toast("Carro criado com sucesso!", { position: "top-right", hideProgressBar: false, className: "bg-success text-white" });
                    navigate(-1);
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}