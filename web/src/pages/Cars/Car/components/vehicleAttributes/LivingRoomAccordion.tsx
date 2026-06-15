import { AccordionBody, AccordionHeader, AccordionItem, Col, Input, Label, Row } from "reactstrap";
import { useFormikContext } from "formik";
import Select from "react-select";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload } from "common/models/car.model";

export interface AccordionProps {
    accordionId: string;
}

const layoutOptions = [
    { value: "face_to_face", label: "Frente a Frente" },
    { value: "l_shape",      label: "Sala em L" },
    { value: "panoramic",    label: "Salão Panorâmico" },
];

export default function LivingRoomAccordion({ accordionId }: AccordionProps) {
    const { values, setFieldValue } = useFormikContext<ICarUpdatePayload>();
    const lr = values.vehicle_attributes?.living_room;
    const hb = values.vehicle_attributes?.habitation_basics;

    return (
        <AccordionItem>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-sofa-line me-2" />Sala</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                <Row className="mb-2">
                    <Col lg={4}>
                        <Label>Tipo de sala</Label>
                        <Select
                            isClearable
                            placeholder="Selecionar"
                            options={layoutOptions}
                            value={layoutOptions.find(o => o.value === lr?.layout) ?? null}
                            onChange={(opt: any) => setFieldValue("vehicle_attributes.living_room.layout", opt?.value ?? null)}
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2} className="d-flex align-items-end">
                        <XInputCheckbox
                            name="vehicle_attributes.living_room.has_extending_table"
                            label="Acrescento de mesa"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={3}>
                        {/* habitation_basics.sleeps — campo da habitação (capacidade
                            base da célula), renderizado aqui por proximidade ao
                            layout. Distinto de cars.seats (lugares com cinto). */}
                        <Label for="vehicle_attributes.habitation_basics.sleeps">
                            Número de dormidas
                        </Label>
                        <Input
                            type="number"
                            id="vehicle_attributes.habitation_basics.sleeps"
                            name="vehicle_attributes.habitation_basics.sleeps"
                            min={1}
                            max={12}
                            step={1}
                            value={hb?.sleeps ?? ""}
                            onChange={(e) => {
                                const v = e.target.value;
                                setFieldValue(
                                    "vehicle_attributes.habitation_basics.sleeps",
                                    v === "" ? null : Number(v),
                                );
                            }}
                            className="mb-1"
                        />
                        <small className="text-muted">
                            Pessoas que a autocaravana acomoda para dormir
                            (pode ser diferente do número de lugares).
                        </small>
                    </Col>
                </Row>

            </AccordionBody>
        </AccordionItem>
    );
}
