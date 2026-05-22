import { AccordionBody, AccordionHeader, AccordionItem, Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload } from "common/models/car.model";

export interface AccordionProps {
    accordionId: string;
}

export default function SecurityAccordion({ accordionId }: AccordionProps) {
    useFormikContext<ICarUpdatePayload>();

    return (
        <AccordionItem>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-lock-line me-2" />Segurança e Fechaduras</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.security.has_alarm"
                            label="Alarme"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.security.has_hatch_lock"
                            label="Fecho alçapão"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.security.has_cabin_lock"
                            label="Fecho cabine"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.security.has_safe_door"
                            label="Porta de cofre"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.security.has_gas_lock"
                            label="Fecho gás"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.security.has_entry_door_lock"
                            label="Fecho porta entrada"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <Row>
                    <Col lg={6}>
                        <XInput
                            name="vehicle_attributes.security.other_locks_notes"
                            label="Outras fechaduras (notas)"
                            className="mb-3"
                        />
                    </Col>
                </Row>

            </AccordionBody>
        </AccordionItem>
    );
}
