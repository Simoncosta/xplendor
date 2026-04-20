//React
import { useEffect, useRef, useState } from "react";
import Select from "react-select";
import { Button, Col, Label, Row } from "reactstrap";

// Components
import XInput from "Components/Common/XInput";

// Forms
import { useFormikContext } from "formik";

// Models
import { ICarUpdatePayload } from "common/models/car.model";

// Redux
import { colorsOptions, conditionsOptions, seatsOptions, segmentOptions } from "common/data/cars";
import { getCarCategories } from "helpers/laravel_helper";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import { DEFAULT_VEHICLE_ATTRIBUTES } from "slices/cars/car.defaults";

const motorhomeSubsegmentOptions = [
    { value: "autocaravana", label: "Autocaravana" },
    { value: "caravana", label: "Caravana" },
    { value: "residencial", label: "Residencial" },
];

const bedTypeOptions = [
    { value: "central", label: "Central" },
    { value: "rebatível na cabine", label: "Rebatível na cabine" },
    { value: "beliche", label: "Beliche" },
    { value: "transversal", label: "Transversal" },
    { value: "outra", label: "Outra" },
];

export default function CarVehicleDetailsDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();
    const [categoryOptions, setCategoryOptions] = useState<{ value: number; label: string }[]>([]);
    const [loadingCategories, setLoadingCategories] = useState(false);
    const prevVehicleTypeRef = useRef(values.vehicle_type);

    useEffect(() => {
        if (prevVehicleTypeRef.current !== values.vehicle_type) {
            setFieldValue("segment", null);
            setFieldValue("car_category_id", null);

            if (values.vehicle_type !== "motorhome") {
                setFieldValue("subsegment", null);
                setFieldValue("vehicle_attributes", { ...DEFAULT_VEHICLE_ATTRIBUTES });
            }
        }

        prevVehicleTypeRef.current = values.vehicle_type;
    }, [values.vehicle_type, setFieldValue]);

    useEffect(() => {
        if (values.vehicle_type === "motorhome" && values.subsegment && values.segment !== values.subsegment) {
            setFieldValue("segment", values.subsegment);
        }
    }, [values.vehicle_type, values.subsegment, values.segment, setFieldValue]);

    useEffect(() => {
        if (values.vehicle_type === "motorhome" && values.subsegment !== "autocaravana" && values.car_category_id) {
            setFieldValue("car_category_id", null);
        }
    }, [values.vehicle_type, values.subsegment, values.car_category_id, setFieldValue]);

    useEffect(() => {
        let isMounted = true;

        const fetchCategories = async () => {
            if (values.vehicle_type !== "motorhome" || values.subsegment !== "autocaravana") {
                setCategoryOptions([]);
                return;
            }

            setLoadingCategories(true);

            try {
                const response = await getCarCategories("motorhome");
                const categories = (response?.data || []).map((category: any) => ({
                    value: Number(category.id),
                    label: category.name ?? String(category.slug ?? category.id),
                })).filter((category: { value: number; label: string }) => Number.isFinite(category.value));

                if (isMounted) {
                    setCategoryOptions(categories);
                }
            } catch (error) {
                if (isMounted) {
                    setCategoryOptions([]);
                }
            } finally {
                if (isMounted) {
                    setLoadingCategories(false);
                }
            }
        };

        fetchCategories();

        return () => {
            isMounted = false;
        };
    }, [values.vehicle_type, values.subsegment]);

    const isMotorhome = values.vehicle_type === "motorhome";
    const showMotorhomeCategory = isMotorhome && values.subsegment === "autocaravana";
    const beds = Array.isArray(values.vehicle_attributes?.beds)
        ? values.vehicle_attributes.beds
        : [];

    return (
        <div className="mt-4">
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Detalhes da Viatura</h5>
            </div>

            <Row>
                {isMotorhome ? (
                    <Col lg={2}>
                        <Label for="subsegment">
                            Tipo de Autocaravana: <span className="text-danger">*</span>
                        </Label>
                        <Select
                            id="subsegment"
                            name="subsegment"
                            options={motorhomeSubsegmentOptions}
                            value={motorhomeSubsegmentOptions.find((option) => option.value === values.subsegment) || null}
                            onChange={(option: any) => {
                                const subsegment = option?.value || null;

                                setFieldValue("subsegment", subsegment);
                                setFieldValue("segment", subsegment);
                                if (subsegment !== "autocaravana") {
                                    setFieldValue("car_category_id", null);
                                }
                                setFieldTouched("subsegment", true);
                            }}
                            className="mb-3"
                            required
                        />
                    </Col>
                ) : (
                    <Col lg={2}>
                        <Label for="segment">
                            Segmento: <span className="text-danger">*</span>
                        </Label>
                        <Select
                            id="segment"
                            name="segment"
                            options={segmentOptions}
                            value={segmentOptions.find((option: any) => option.value === values.segment) || null}
                            onChange={(option: any) => {
                                setFieldValue("segment", option?.value || null);
                                setFieldTouched("segment", true);
                            }}
                            className="mb-3"
                            required
                        />
                    </Col>
                )}
                {showMotorhomeCategory && (
                    <Col lg={2}>
                        <Label for="car_category_id">
                            Categoria (opcional)
                        </Label>
                        <Select
                            id="car_category_id"
                            name="car_category_id"
                            options={categoryOptions}
                            isLoading={loadingCategories}
                            isClearable
                            placeholder="Selecionar categoria"
                            value={categoryOptions.find((option) => option.value === Number(values.car_category_id)) || null}
                            onChange={(option: any) => {
                                setFieldValue("car_category_id", option?.value || null);
                                setFieldTouched("car_category_id", true);
                            }}
                            className="mb-3"
                        />
                    </Col>
                )}
                <Col lg={2}>
                    <Label for="seats">
                        Lugares: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="seats"
                        name="seats"
                        options={seatsOptions}
                        value={seatsOptions.find((option: any) => option.value === values.seats) || null}
                        onChange={(option: any) => {
                            setFieldValue("seats", option?.value || null);
                            setFieldTouched("seats", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={2}>
                    <Label for="exterior_color">
                        Cor: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="exterior_color"
                        name="exterior_color"
                        options={colorsOptions}
                        value={colorsOptions.find((option: any) => option.value === values.exterior_color) || null}
                        onChange={(option: any) => {
                            setFieldValue("exterior_color", option?.value || null);
                            setFieldTouched("exterior_color", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={2}>
                    <XInputCheckbox
                        name="is_metallic"
                        label="Cor Metálica"
                        className="mb-3"
                    />
                </Col>
                <Col lg={2}>
                    <Label for="condition">
                        Estado: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="condition"
                        name="condition"
                        options={conditionsOptions}
                        value={conditionsOptions.find((option: any) => option.value === values.condition) || null}
                        onChange={(option: any) => {
                            setFieldValue("condition", option?.value || null);
                            setFieldTouched("condition", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
                <Col lg={2}>
                    <XInput
                        type="number"
                        name="mileage_km"
                        label="Quilometragem (km)"
                        className="mb-3"
                        required
                    />
                </Col>
            </Row>

            {values.vehicle_type === "motorhome" && (
                <Row>
                    <Col lg={2}>
                        <XInput
                            type="number"
                            step="0.1"
                            name="vehicle_attributes.length"
                            label="Comprimento (cm)"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInput
                            type="number"
                            step="0.1"
                            name="vehicle_attributes.width"
                            label="Largura (cm)"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInput
                            type="number"
                            step="0.1"
                            name="vehicle_attributes.height"
                            label="Altura (cm)"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInput
                            type="number"
                            name="vehicle_attributes.gross_weight"
                            label="Peso bruto (kg)"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.has_bathroom"
                            label="Casa de banho"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.has_kitchen"
                            label="Cozinha"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInput
                            type="number"
                            name="vehicle_attributes.autonomy"
                            label="Autonomia (km)"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={12}>
                        <div className="border rounded-3 p-3 mb-3">
                            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                <div>
                                    <Label className="mb-1">Camas</Label>
                                    <div className="text-muted fs-12">Adiciona quantas camas forem necessárias.</div>
                                </div>
                                <Button
                                    type="button"
                                    color="light"
                                    className="border"
                                    onClick={() => setFieldValue("vehicle_attributes.beds", [...beds, { type: "" }])}
                                >
                                    Adicionar cama
                                </Button>
                            </div>

                            {beds.length === 0 ? (
                                <div className="text-muted fs-13">Sem camas adicionadas.</div>
                            ) : (
                                <Row>
                                    {beds.map((bed, index) => (
                                        <Col lg={3} key={`bed-${index}`}>
                                            <div className="d-flex gap-2 align-items-start mb-3">
                                                <div className="flex-grow-1">
                                                    <Select
                                                        name={`vehicle_attributes.beds.${index}.type`}
                                                        options={bedTypeOptions}
                                                        value={bedTypeOptions.find((option) => option.value === bed?.type) || null}
                                                        placeholder="Tipo de cama"
                                                        onChange={(option: any) => {
                                                            const nextBeds = [...beds];
                                                            nextBeds[index] = { type: option?.value || "" };
                                                            setFieldValue("vehicle_attributes.beds", nextBeds);
                                                        }}
                                                    />
                                                </div>
                                                <Button
                                                    type="button"
                                                    color="light"
                                                    className="border"
                                                    onClick={() => {
                                                        const nextBeds = beds.filter((_, bedIndex) => bedIndex !== index);
                                                        setFieldValue("vehicle_attributes.beds", nextBeds);
                                                    }}
                                                >
                                                    Remover
                                                </Button>
                                            </div>
                                        </Col>
                                    ))}
                                </Row>
                            )}
                        </div>
                    </Col>
                </Row>
            )}
        </div>
    )
}
