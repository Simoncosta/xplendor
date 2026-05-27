import { AccordionBody, AccordionHeader, AccordionItem, Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XInputCheckbox from "Components/Common/XInputCheckbox";
import type { ICarUpdatePayload } from "common/models/car.model";

export interface AccordionProps {
    accordionId: string;
}

export default function ExteriorAccordion({ accordionId }: AccordionProps) {
    const { values } = useFormikContext<ICarUpdatePayload>();
    const ext = values.vehicle_attributes?.exterior;

    return (
        <AccordionItem>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-road-map-line me-2" />Exterior</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_awning"
                            label="Toldo"
                            className="mb-3"
                        />
                    </Col>
                    {ext?.has_awning && (
                        <Col lg={2}>
                            <XInput
                                name="vehicle_attributes.exterior.awning_brand"
                                label="Marca do toldo"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_national_antenna"
                            label="Antena nacional"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_parabolic_antenna"
                            label="Antena parabólica"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_bike_rack"
                            label="Suporte de bicicletas"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_motorbike_rack"
                            label="Suporte de mota"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_electric_step"
                            label="Degrau eléctrico"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_manual_step"
                            label="Degrau manual"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_stabilizers"
                            label="Macacos estabilizadores"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <Row>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_spare_wheel"
                            label="Pneu suplente"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_fix_n_go_kit"
                            label="Kit Fix&Go"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_bull_eye"
                            label="Olho de boi"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_external_wc"
                            label="Sanita exterior"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_hubcaps"
                            label="Tampões"
                            className="mb-3"
                        />
                    </Col>
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.has_external_ladder"
                            label="Escada exterior"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                <hr className="my-3" />
                <h6 className="mb-3"><i className="ri-archive-line me-2" />Garagem</h6>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.exterior.garage.has_garage"
                            label="Garagem"
                            className="mb-3"
                        />
                    </Col>
                </Row>
                {ext?.garage?.has_garage && (
                    <Row className="mb-2">
                        <Col lg={2}>
                            <XInputCheckbox
                                name="vehicle_attributes.exterior.garage.has_double_opening"
                                label="Abertura dos dois lados"
                                className="mb-3"
                            />
                        </Col>
                        <Col lg={2}>
                            <XInputCheckbox
                                name="vehicle_attributes.exterior.garage.is_spacious"
                                label="Espaçosa"
                                className="mb-3"
                            />
                        </Col>
                        <Col lg={2}>
                            <XInputCheckbox
                                name="vehicle_attributes.exterior.garage.has_height_adjuster"
                                label="Altura ajustável"
                                className="mb-3"
                            />
                        </Col>
                    </Row>
                )}

            </AccordionBody>
        </AccordionItem>
    );
}
