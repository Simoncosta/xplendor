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
import { closeCarSale } from "slices/car-sales/thunk";
// Utils
import { buildCarFormData } from "./utils/buildCarFormData";
import { ICarSalePayload } from "common/models/car-sale.model";

const selectCarState = (state: any) => state.Car;
const selectCarSaleState = (state: any) => state.CarSale;

const selectCarUpdateViewModel = createSelector(
    [selectCarState, selectCarSaleState],
    (carState, carSaleState) => ({
        car: carState.data.car,
        loadingShow: carState.loading.show,
        loadingUpdate: carState.loading.update,
        loadingSale: carSaleState.loading.create,
    })
);

export default function CarUpdate() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Editar Carro | Xplendor";

    // State
    const [companyId, setCompanyId] = useState<number>(0);

    const { car, loadingShow, loadingUpdate, loadingSale } = useSelector(selectCarUpdateViewModel);

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
                saleLoading={loadingSale}
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
                onSubmitSold={async (values: any, saleData: ICarSalePayload) => {
                    if (loadingSale) return;

                    const fd = buildCarFormData({
                        ...values,
                        status: "sold",
                    });

                    const appendSaleField = (key: string, value: unknown) => {
                        if (value === null || value === undefined || value === "") {
                            return;
                        }

                        fd.append(key, String(value));
                    };

                    appendSaleField("sale_price", saleData.sale_price);
                    appendSaleField("buyer_gender", saleData.buyer_gender);
                    appendSaleField("buyer_age_range", saleData.buyer_age_range);
                    appendSaleField("sale_channel", saleData.sale_channel);
                    appendSaleField("buyer_name", saleData.buyer_name);
                    appendSaleField("buyer_phone", saleData.buyer_phone);
                    appendSaleField("buyer_email", saleData.buyer_email);
                    fd.append("contact_consent", saleData.contact_consent ? "1" : "0");
                    appendSaleField("notes", saleData.notes);

                    try {
                        await dispatch(closeCarSale({
                            companyId,
                            carId: Number(id),
                            formData: fd,
                        })).unwrap();

                        await dispatch(showCar({ companyId, id: Number(id) })).unwrap();
                        toast("Venda concluída com sucesso!", { position: "top-right", hideProgressBar: false, className: "bg-success text-white" });
                    } catch (error) {
                        // Mantém o modal disponível para nova tentativa em caso de erro.
                    }
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    );
}
