import { AccordionBody, AccordionHeader, AccordionItem, Col, Label, Row } from "reactstrap";
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
                </Row>

            </AccordionBody>
        </AccordionItem>
    );
}
