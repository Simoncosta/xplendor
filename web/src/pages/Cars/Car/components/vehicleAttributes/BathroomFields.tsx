import Select from "react-select";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import { Col, Label, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload } from "common/models/car.model";

const showerTypeOptions = [
    { value: "separate", label: "Separado" },
    { value: "independent", label: "Independente" },
    { value: "combined", label: "Combinado" },
];

/**
 * Campos da casa de banho (`habitation_basics.bathroom`). Usados no acordeão "Casa de
 * Banho" das caravanas e na secção "Duche e WC" das autocaravanas.
 */
export default function BathroomFields() {
    const { values, setFieldValue } = useFormikContext<ICarUpdatePayload>();
    const bathroom = values.vehicle_attributes?.habitation_basics?.bathroom;

    return (
        <>
            <Row>
                <Col lg={12}>
                    <XInputCheckbox
                        name="vehicle_attributes.habitation_basics.has_bathroom"
                        label="Tem casa de banho"
                        className="mb-3"
                    />
                </Col>
            </Row>
            {values.vehicle_attributes?.habitation_basics?.has_bathroom && (
                <Row>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.bathroom.has_toilet"
                            label="Sanita"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.bathroom.has_shower"
                            label="Duche"
                            className="mb-3"
                        />
                    </Col>
                    {bathroom?.has_shower && (
                        <Col lg={2}>
                            <Label>Tipo de duche</Label>
                            <Select
                                styles={reactSelectTheme}
                                menuPortalTarget={document.body}
                                isClearable
                                placeholder="Selecionar"
                                options={showerTypeOptions}
                                value={showerTypeOptions.find(o => o.value === bathroom?.shower_type) ?? null}
                                onChange={(opt: any) => setFieldValue("vehicle_attributes.habitation_basics.bathroom.shower_type", opt?.value ?? null)}
                                className="mb-3"
                            />
                        </Col>
                    )}
                    {/* 2026-06-29 — Separação "tem depósito" (checkbox)
                        de "quantos litros". Padrão has_fridge → fridge_*.
                        Retro-compat: `normalizeVehicleAttributes` do
                        CarEditor marca as checkboxes quando os litros
                        > 0 e o campo ainda não existe no JSON. */}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.bathroom.has_clean_water_tank"
                            label="Dep. águas limpas"
                            className="mb-3"
                        />
                    </Col>
                    {bathroom?.has_clean_water_tank && (
                        <Col lg={2}>
                            <XInput
                                type="number"
                                name="vehicle_attributes.habitation_basics.bathroom.clean_water_litres"
                                label="Litros"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.habitation_basics.bathroom.has_waste_water_tank"
                            label="Dep. águas residuais"
                            className="mb-3"
                        />
                    </Col>
                    {bathroom?.has_waste_water_tank && (
                        <Col lg={2}>
                            <XInput
                                type="number"
                                name="vehicle_attributes.habitation_basics.bathroom.waste_water_litres"
                                label="Litros"
                                className="mb-3"
                            />
                        </Col>
                    )}
                </Row>
            )}
        </>
    );
}
