import Select from "react-select";
import { AccordionBody, AccordionHeader, AccordionItem, Col, Label, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload, ChassisType } from "common/models/car.model";

export interface AccordionProps {
    accordionId: string;
}

type ChassisOpt = { value: ChassisType; label: string };

const chassisTypeOptions: ChassisOpt[] = [
    { value: "standard", label: "Standard" },
    { value: "alko",     label: "Alko" },
    { value: "other",    label: "Outro" },
];

export default function ChassisStructureAccordion({ accordionId }: AccordionProps) {
    const { values, setFieldValue } = useFormikContext<ICarUpdatePayload>();
    const cs = values.vehicle_attributes?.chassis_structure;

    return (
        <AccordionItem>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-tools-line me-2" />Chassis e Estrutura</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                <Row className="mb-2">
                    <Col lg={2}>
                        <Label>Tipo de chassis</Label>
                        <Select
                            isClearable
                            placeholder="Selecionar"
                            options={chassisTypeOptions}
                            value={chassisTypeOptions.find(o => o.value === cs?.chassis_type) ?? null}
                            onChange={(opt: ChassisOpt | null) => setFieldValue("vehicle_attributes.chassis_structure.chassis_type", opt?.value ?? null)}
                            className="mb-3"
                        />
                    </Col>
                    {cs?.chassis_type && (
                        <Col lg={4}>
                            <XInput
                                name="vehicle_attributes.chassis_structure.chassis_notes"
                                label="Notas de chassis"
                                className="mb-3"
                            />
                        </Col>
                    )}
                </Row>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_turbovent_skylight"
                            label="Clarabóia turbovent"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_panoramic_skylight"
                            label="Clarabóia panorâmica"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_40x40_skylight"
                            label="Clarabóia 40×40"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={4}>
                        <XInput
                            name="vehicle_attributes.chassis_structure.other_skylights_notes"
                            label="Outras clarabóias"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <Row>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_remifront"
                            label="Remifront"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_window_blackouts"
                            label="Tapa-luz janelas"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_mosquito_nets"
                            label="Mosquiteiras janelas"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_door_mosquito_net"
                            label="Porta mosquiteira"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_cabin_blackouts"
                            label="Tapa-luz cabine"
                            className="mb-3"
                        />
                    </Col>
                    {cs?.has_cabin_blackouts && (
                        <Col lg={2}>
                            <XInput
                                name="vehicle_attributes.chassis_structure.cabin_blackout_type"
                                label="Tipo tapa-luz cabine"
                                className="mb-3"
                            />
                        </Col>
                    )}
                </Row>

            </AccordionBody>
        </AccordionItem>
    );
}
