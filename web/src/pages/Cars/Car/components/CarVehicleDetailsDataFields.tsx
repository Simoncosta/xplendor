//React
import { useEffect, useRef, useState } from "react";
import Select from "react-select";
import { Col, Label, Row } from "reactstrap";

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

export default function CarVehicleDetailsDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<ICarUpdatePayload>();
    const [dynamicOptions, setDynamicOptions] = useState<{ value: string; label: string }[]>([]);
    const [loadingCategories, setLoadingCategories] = useState(false);
    const prevVehicleTypeRef = useRef(values.vehicle_type);

    useEffect(() => {
        if (prevVehicleTypeRef.current !== values.vehicle_type) {
            setFieldValue("segment", null);

            if (values.vehicle_type !== "motorhome") {
                setFieldValue("vehicle_attributes", { ...DEFAULT_VEHICLE_ATTRIBUTES });
            }
        }

        prevVehicleTypeRef.current = values.vehicle_type;
    }, [values.vehicle_type, setFieldValue]);

    useEffect(() => {
        let isMounted = true;

        const fetchCategories = async () => {
            if (values.vehicle_type !== "motorhome") {
                setDynamicOptions([]);
                return;
            }

            setLoadingCategories(true);

            try {
                const response = await getCarCategories("motorhome");
                const categories = (response?.data || []).map((category: any) => ({
                    value: category.slug ?? category.id,
                    label: category.name ?? String(category.slug ?? category.id),
                }));

                if (isMounted) {
                    setDynamicOptions(categories);
                }
            } catch (error) {
                if (isMounted) {
                    setDynamicOptions([]);
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
    }, [values.vehicle_type]);

    const finalOptions = values.vehicle_type === "motorhome" ? dynamicOptions : segmentOptions;

    return (
        <div className="mt-4">
            <div className={`mb-2 border-bottom pb-2`}>
                <h5 className="card-title">Detalhes da Viatura</h5>
            </div>

            <Row>
                <Col lg={2}>
                    <Label for="segment">
                        Segmento: <span className="text-danger">*</span>
                    </Label>
                    <Select
                        id="segment"
                        name="segment"
                        options={finalOptions}
                        isLoading={loadingCategories}
                        value={finalOptions.find((option: any) => option.value === values.segment) || null}
                        onChange={(option: any) => {
                            setFieldValue("segment", option?.value || null);
                            setFieldTouched("segment", true);
                        }}
                        className="mb-3"
                        required
                    />
                </Col>
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
                            name="vehicle_attributes.beds"
                            label="Dormidas"
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
                            name="vehicle_attributes.autonomy_km"
                            label="Autonomia (km)"
                            className="mb-3"
                        />
                    </Col>
                </Row>
            )}
        </div>
    )
}
