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

// M2.2 — marcas de motor aceites. A autocaravana tem marca COMERCIAL
// (Challenger/McLouis), o MOTOR por baixo é Fiat/Renault/etc. Distinto
// também de `vehicle_attributes.chassis_structure.chassis_type` (tipo
// de chassis, atributo de habitação). Lista tem de ficar em sincronia
// com VALID_ENGINE_BRANDS em server/app/Http/Requests/CarRequest.php
// (defesa em profundidade — backend faz Rule::in com a mesma lista).
const engineBrandOptions = [
    "Fiat", "Renault", "Ford", "Citroën", "Mercedes", "Iveco", "Peugeot", "VW",
].map(b => ({ value: b, label: b }));

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
    const hasMotorFields = values.vehicle_type !== "caravan";
    const isHabitationVehicle = values.vehicle_type === "motorhome" || values.vehicle_type === "caravan";
    // M2.2 — em motorhome/caravan, a Row 1 ganha Marca do motor ao lado
    // do Modelo; Marca/Modelo encolhem de lg=4 para lg=3 para acomodar 12.
    const brandModelLg = isHabitationVehicle ? 3 : 4;

    const brandOptions = useMemo(() => brands.map((brand: any) => ({
        value: brand.id,
        label: brand.name,
    })), [brands]);

    useEffect(() => {
        const brandVehicleType =
            values.vehicle_type === "caravan"
                ? "motorhome"
                : values.vehicle_type;

        dispatch(getCarBrands(brandVehicleType));
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

        const brandVehicleType =
            values.vehicle_type === "caravan"
                ? "motorhome"
                : values.vehicle_type;

        dispatch(getCarModels({
            brand_id: values.car_brand_id,
            vehicle_type: brandVehicleType,
        }));
    }, [dispatch, setFieldValue, values.car_brand_id, values.vehicle_type]);

    useEffect(() => {
        if (hasMotorFields) return;

        setFieldValue("fuel_type", null);
        setFieldValue("engine_capacity_cc", null);
        setFieldValue("power_hp", null);
        setFieldValue("transmission", null);
    }, [hasMotorFields, setFieldValue]);

    return (
        <div className="mt-4">
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Dados da Viatura</h5>
            </div>

            {/* Grelha distribuída em 3 linhas de 12 col, sem buracos:
                  L1: identidade           (Marca+Modelo+Mês+Ano)
                  L2: motor                (Combust+CC+CV+Transm+Portas) — só hasMotorFields
                  L3: versões + (Portas se caravan) */}
            <Row>
                <Col lg={brandModelLg}>
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
                <Col lg={brandModelLg}>
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
                {isHabitationVehicle && (
                    <Col lg={2}>
                        <Label for="engine_brand">Marca do motor:</Label>
                        <Select
                            id="engine_brand"
                            name="engine_brand"
                            isClearable
                            placeholder="Selecionar"
                            options={engineBrandOptions}
                            value={engineBrandOptions.find((o) => o.value === values.engine_brand) || null}
                            onChange={(option: any) => {
                                setFieldValue("engine_brand", option?.value || null);
                                setFieldTouched("engine_brand", true);
                            }}
                            className="mb-3"
                        />
                    </Col>
                )}
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
            </Row>

            {hasMotorFields && (
                <Row>
                    <Col lg={3}>
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
                            label="Capacidade (CC)"
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
                    <Col lg={3}>
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
                            type="number"
                            name="doors"
                            label="Portas"
                            className="mb-3"
                        />
                    </Col>
                </Row>
            )}

            <Row>
                {!hasMotorFields && (
                    <Col lg={2}>
                        <XInput
                            type="number"
                            name="doors"
                            label="Portas"
                            className="mb-3"
                        />
                    </Col>
                )}
                <Col lg={4}>
                    <XInput
                        name="version"
                        label="Versão"
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={6}>
                    {/* Helper passa para tooltip on-demand (ícone ⓘ no label)
                        em vez de bloco permanente por baixo — liberta espaço
                        vertical e a info fica acessível em hover/focus/tap. */}
                    <XInput
                        name="public_version_name"
                        label="Versão (web)"
                        hint="Opcional. Se preenchido, substitui a 'Versão' na ficha pública do site. Útil quando o nome interno é técnico (ex.: '4 Matic 381') e queres algo mais comercial."
                        className="mb-3"
                    />
                </Col>
            </Row>
        </div>
    )
}
