import { useEffect } from "react";
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

    // M2.7 — limpeza activa: quando o user desmarca a suspensão pneumática,
    // o compressor passa também a false. Impede o estado absurdo "compressor
    // sem suspensão pneumática" mesmo depois de o user marcar e desmarcar.
    useEffect(() => {
        if (!cs?.has_air_suspension && cs?.has_air_suspension_compressor) {
            setFieldValue(
                "vehicle_attributes.chassis_structure.has_air_suspension_compressor",
                false,
            );
        }
    }, [cs?.has_air_suspension, cs?.has_air_suspension_compressor, setFieldValue]);

    return (
        <AccordionItem id={`hab-acc-${accordionId}`}>
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

                {/* Lote 3 — skylights, remifront e mosquiteiras movidos para
                    Mobiliário Interior (decisão UX Matilde). As CHAVES JSON
                    permanecem em `chassis_structure.*` (`has_turbovent_skylight`,
                    `has_panoramic_skylight`, `has_40x40_skylight`,
                    `other_skylights_notes`, `has_remifront`, `has_mosquito_nets`,
                    `has_door_mosquito_net`) para NÃO partir dados em prod —
                    desalinhamento intencional documentado no CLAUDE.md sec 6.1. */}

                {/* M2.7 — Suspensão pneumática. Compressor é sub-campo
                    condicional (só editável quando a pneumática está activa).
                    Padrão dos sub-campos da garagem (ExteriorAccordion).
                    Lote 3 — rodado duplo + estabilizadores adicionados nesta Row
                    (`has_stabilizers` permanece com chave JSON em `exterior.*`). */}
                <Row className="mb-2">
                    <Col lg={3}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_air_suspension"
                            label="Suspensão pneumática"
                            className="mb-3"
                        />
                    </Col>
                    {cs?.has_air_suspension && (
                        <Col lg={3}>
                            <XInputCheckbox
                                name="vehicle_attributes.chassis_structure.has_air_suspension_compressor"
                                label="Com compressor"
                                className="mb-3"
                            />
                        </Col>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_dual_rear_wheel"
                            label="Rodado duplo"
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
                            name="vehicle_attributes.chassis_structure.has_window_blackouts"
                            label="Tapa-luz janelas"
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
