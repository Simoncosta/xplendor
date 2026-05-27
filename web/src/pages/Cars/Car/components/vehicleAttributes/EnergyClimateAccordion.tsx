import Select from "react-select";
import { AccordionBody, AccordionHeader, AccordionItem, Col, Label, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload, WaterHeaterSource, AmbientHeatingSource } from "common/models/car.model";

export interface AccordionProps {
    accordionId: string;
}

type SourceOpt = { value: WaterHeaterSource | AmbientHeatingSource; label: string };

const heatingSourceOptions: SourceOpt[] = [
    { value: "electric", label: "Eléctrico" },
    { value: "gas",      label: "Gás" },
    { value: "diesel",   label: "Gasóleo" },
    { value: "none",     label: "Nenhum" },
];

export default function EnergyClimateAccordion({ accordionId }: AccordionProps) {
    const { values, setFieldValue } = useFormikContext<ICarUpdatePayload>();
    const ec = values.vehicle_attributes?.energy_climate;

    return (
        <AccordionItem>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-flashlight-line me-2" />Energia e Aquecimento</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                <Row className="mb-2">
                    <Col lg={2}>
                        <Label>Aquecimento de água</Label>
                        <Select
                            isClearable
                            placeholder="Fonte"
                            options={heatingSourceOptions}
                            value={heatingSourceOptions.find(o => o.value === ec?.water_heater_source) ?? null}
                            onChange={(opt: SourceOpt | null) => setFieldValue("vehicle_attributes.energy_climate.water_heater_source", opt?.value ?? null)}
                            className="mb-3"
                        />
                    </Col>
                    {ec?.water_heater_source && ec.water_heater_source !== "none" && (
                        <Col lg={2}>
                            <XInput
                                name="vehicle_attributes.energy_climate.water_heater_brand"
                                label="Marca (aquec. água)"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <Label>Aquecimento ambiente</Label>
                        <Select
                            isClearable
                            placeholder="Fonte"
                            options={heatingSourceOptions}
                            value={heatingSourceOptions.find(o => o.value === ec?.ambient_heating_source) ?? null}
                            onChange={(opt: SourceOpt | null) => setFieldValue("vehicle_attributes.energy_climate.ambient_heating_source", opt?.value ?? null)}
                            className="mb-3"
                        />
                    </Col>
                    {ec?.ambient_heating_source && ec.ambient_heating_source !== "none" && (
                        <Col lg={2}>
                            <XInput
                                name="vehicle_attributes.energy_climate.ambient_heating_brand"
                                label="Marca (aquec. ambiente)"
                                className="mb-3"
                            />
                        </Col>
                    )}
                </Row>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.energy_climate.has_solar_panel"
                            label="Painel solar"
                            className="mb-3"
                        />
                    </Col>
                    {ec?.has_solar_panel && (
                        <Col lg={2}>
                            <XInput
                                type="number"
                                name="vehicle_attributes.energy_climate.solar_panel_watts"
                                label="Potência (W)"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.energy_climate.has_inverter"
                            label="Inversor/Conversor"
                            className="mb-3"
                        />
                    </Col>
                    {ec?.has_inverter && (
                        <Col lg={2}>
                            <XInput
                                type="number"
                                name="vehicle_attributes.energy_climate.inverter_watts"
                                label="Potência inversor (W)"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.energy_climate.has_external_power_socket"
                            label="Tomada exterior 220V"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <Row>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.energy_climate.has_gpl"
                            label="GPL"
                            className="mb-3"
                        />
                    </Col>
                    {ec?.has_gpl && (
                        <Col lg={2}>
                            <XInput
                                type="number"
                                name="vehicle_attributes.energy_climate.gpl_bottles_count"
                                label="Garrafas GPL"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <XInput
                            type="number"
                            name="vehicle_attributes.energy_climate.battery_count"
                            label="Baterias (total)"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInput
                            type="number"
                            name="vehicle_attributes.energy_climate.cabin_battery_count"
                            label="Baterias cabine"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInput
                            type="number"
                            name="vehicle_attributes.energy_climate.cell_battery_count"
                            label="Baterias célula"
                            className="mb-3"
                        />
                    </Col>
                </Row>

            </AccordionBody>
        </AccordionItem>
    );
}
