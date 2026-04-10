//React
import Select from "react-select";
import { useDispatch, useSelector } from "react-redux";
import { Col, Label, Row } from "reactstrap";

// Components
import XInput from "Components/Common/XInput";

// Forms
import { useFormikContext } from "formik";

// Models
import { ICarUpdatePayload } from "common/models/car.model";

// Redux
import { getCarBrands } from "slices/car-brands/thunk";
import { useEffect, useMemo, useRef } from "react";
import { getCarModels } from "slices/car-models/thunk";
import { fuelTypeOptions, monthsOptions, transmissionOptions } from "common/data/cars";
import { createSelector } from "reselect";

const selectCarBrandState = (state: any) => state.CarBrand;
const selectCarModelState = (state: any) => state.CarModel;

const selectCarBrandOptionsState = createSelector(
    [selectCarBrandState],
    (carBrandState) => ({
        brands: carBrandState.data.brands,
        loading: carBrandState.loading.list,
    })
);

const selectCarModelOptionsState = createSelector(
    [selectCarModelState],
    (carModelState) => ({
        models: carModelState.data.models,
    })
);

export default function CarVehicleDataFields({ isEdit }: { isEdit: boolean }) {
    const dispatch: any = useDispatch();

    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();
    const previousVehicleTypeRef = useRef(values.vehicle_type);

    const { brands, loading } = useSelector(selectCarBrandOptionsState);
    const { models } = useSelector(selectCarModelOptionsState);

    const brandOptions = useMemo(() => brands.map((brand: any) => ({
        value: brand.id,
        label: brand.name,
    })), [brands]);

    useEffect(() => {
        dispatch(getCarBrands(values.vehicle_type));
    }, [dispatch, values.vehicle_type]);

    useEffect(() => {
        const previousVehicleType = previousVehicleTypeRef.current;

        if (previousVehicleType && previousVehicleType !== values.vehicle_type) {
            setFieldValue("car_brand_id", null);
            setFieldValue("car_model_id", null);
        }

        previousVehicleTypeRef.current = values.vehicle_type;
    }, [setFieldValue, values.vehicle_type]);

    const modelOptions = useMemo(() => {
        if (!values.car_brand_id) {
            return [];
        }

        return models.map((model: any) => ({
            value: model.id,
            label: model.name,
        }));
    }, [models, values.car_brand_id]);

    useEffect(() => {
        if (!values.car_brand_id) {
            setFieldValue("car_model_id", null);
            return;
        }

        dispatch(getCarModels({
            brand_id: values.car_brand_id,
            vehicle_type: values.vehicle_type,
        }));
    }, [dispatch, setFieldValue, values.car_brand_id, values.vehicle_type]);

    return (
        <div className="mt-4">
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Dados da Viatura</h5>
            </div>

            <Row>
                <Col lg={4}>
                    <Label for="car_brand_id">
                        Marca: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="car_brand_id"
                        name="car_brand_id"
                        options={brandOptions}
                        value={brandOptions.find((option: any) => option.value === values.car_brand_id) || null}
                        onChange={(option: any) => {
                            setFieldValue("car_brand_id", option?.value || null);
                            setFieldTouched("car_brand_id", true);
                            setFieldValue("car_model_id", null);
                            setFieldTouched("car_model_id", false);
                        }}
                        isDisabled={isEdit}
                        isLoading={loading}
                        className="mb-3"
                    />
                </Col>
                <Col lg={4}>
                    <Label for="car_model_id">
                        Modelo: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="car_model_id"
                        name="car_model_id"
                        options={modelOptions}
                        value={modelOptions.find((option: any) => option.value === values.car_model_id) || null}
                        onChange={(option: any) => {
                            setFieldValue("car_model_id", option?.value || null);
                            setFieldTouched("car_model_id", true);
                        }}
                        isDisabled={isEdit || modelOptions.length === 0}
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label for="registration_month">
                        Mês:
                    </Label>
                    <Select
                        id="registration_month"
                        name="registration_month"
                        options={monthsOptions}
                        value={monthsOptions.find((option: any) => option.value === values.registration_month) || null}
                        onChange={(option: any) => {
                            setFieldValue("registration_month", option?.value || null);
                            setFieldTouched("registration_month", true);
                        }}
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        type="number"
                        name="registration_year"
                        label="Ano"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label for="fuel_type">
                        Combustível:
                    </Label>
                    <Select
                        id="fuel_type"
                        name="fuel_type"
                        options={fuelTypeOptions}
                        value={fuelTypeOptions.find((option: any) => option.value === values.fuel_type) || null}
                        onChange={(option: any) => {
                            setFieldValue("fuel_type", option?.value || null);
                            setFieldTouched("fuel_type", true);
                        }}
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        name="engine_capacity_cc"
                        label="Capacidade do Motor (CC)"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        name="power_hp"
                        label="Potência (CV)"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        type="number"
                        name="doors"
                        label="Portas"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label for="transmission">
                        Transmissão:
                    </Label>
                    <Select
                        id="transmission"
                        name="transmission"
                        options={transmissionOptions}
                        value={transmissionOptions.find((option: any) => option.value === values.transmission) || null}
                        onChange={(option: any) => {
                            setFieldValue("transmission", option?.value || null);
                            setFieldTouched("transmission", true);
                        }}
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        name="version"
                        label="Versão"
                        className="mb-3"
                        required
                    />
                </Col>
            </Row>
        </div>
    )
}
