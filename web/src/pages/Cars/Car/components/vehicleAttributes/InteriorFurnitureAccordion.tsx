import Select from "react-select";
import { AccordionBody, AccordionHeader, AccordionItem, Col, Label, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload, UpholsteryState } from "common/models/car.model";

export interface AccordionProps {
    accordionId: string;
}

type UpholsteryOpt = { value: UpholsteryState; label: string };

const upholsteryOptions: UpholsteryOpt[] = [
    { value: "good",      label: "Bom" },
    { value: "fair",      label: "Razoável" },
    { value: "worn",      label: "Desgastado" },
    { value: "replaced",  label: "Substituído" },
];

export default function InteriorFurnitureAccordion({ accordionId }: AccordionProps) {
    const { values, setFieldValue } = useFormikContext<ICarUpdatePayload>();
    const inf = values.vehicle_attributes?.interior_furniture;

    return (
        <AccordionItem>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-layout-3-line me-2" />Mobiliário Interior</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_foldable_table"
                            label="Mesa rebatível"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_rotating_seats"
                            label="Bancos giratórios"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <Label>Estado dos estofos</Label>
                        <Select
                            isClearable
                            placeholder="Selecionar"
                            options={upholsteryOptions}
                            value={upholsteryOptions.find(o => o.value === inf?.upholstery_state) ?? null}
                            onChange={(opt: UpholsteryOpt | null) => setFieldValue("vehicle_attributes.interior_furniture.upholstery_state", opt?.value ?? null)}
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_curtains"
                            label="Cortinas"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_led_lighting"
                            label="Iluminação LED"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_halo_lighting"
                            label="Iluminação halo"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_tv_support"
                            label="Suporte TV"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_tv"
                            label="TV"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_command_panel"
                            label="Painel de comandos"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <Row>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_water_infiltrations"
                            label="Infiltrações de água"
                            className="mb-3"
                        />
                    </Col>
                    {inf?.has_water_infiltrations && (
                        <Col lg={4}>
                            <XInput
                                name="vehicle_attributes.interior_furniture.infiltrations_notes"
                                label="Notas sobre infiltrações"
                                className="mb-3"
                            />
                        </Col>
                    )}
                </Row>

            </AccordionBody>
        </AccordionItem>
    );
}
