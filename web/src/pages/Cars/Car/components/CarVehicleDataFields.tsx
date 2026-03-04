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
import { useEffect } from "react";
import { getCarModels } from "slices/car-models/thunk";
import { fuelTypeOptions, monthsOptions, transmissionOptions } from "common/data/cars";

export default function CarVehicleDataFields({ isEdit }: { isEdit: boolean }) {
    const dispatch: any = useDispatch();

    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();

    // Redux direto, sem reselect desnecessário
    const { brands, meta, loading } = useSelector(
        (state: any) => state.CarBrand
    );

    // Fetch sempre que mudar de tamanho
    useEffect(() => {
        dispatch(getCarBrands());
    }, [dispatch]);

    const { models } = useSelector(
        (state: any) => state.CarModel
    );

    useEffect(() => {
        if (!values.car_brand_id) return;
        dispatch(getCarModels(values.car_brand_id));
    }, [dispatch, values.car_brand_id]);

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
                        options={brands.map((brand: any) => ({
                            value: brand.id,
                            label: brand.name,
                        }))}
                        value={brands
                            .map((brand: any) => ({
                                value: brand.id,
                                label: brand.name,
                            }))
                            .find((option: any) => option.value === values.car_brand_id) || null}
                        onChange={(option: any) => {
                            setFieldValue("car_brand_id", option?.value || null);
                            setFieldTouched("car_brand_id", true);
                            // Limpar modelo ao mudar marca
                            setFieldValue("car_model_id", null);
                            setFieldTouched("car_model_id", false);
                        }}
                        isDisabled={isEdit} // Marca não editável
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
                        options={models.map((model: any) => ({
                            value: model.id,
                            label: model.name,
                        }))}
                        value={models
                            .map((model: any) => ({
                                value: model.id,
                                label: model.name,
                            }))
                            .find((option: any) => option.value === values.car_model_id) || null}
                        onChange={(option: any) => {
                            setFieldValue("car_model_id", option?.value || null);
                            setFieldTouched("car_model_id", true);
                        }}
                        isDisabled={isEdit || models.length === 0} // Modelo não editável
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