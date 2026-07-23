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

// Lote 3 — `excellent` ordena ACIMA de `good` (qualidade decrescente);
// `replaced` fica em paralelo (estofos refeitos é dimensão ortogonal).
const upholsteryOptions: UpholsteryOpt[] = [
    { value: "excellent", label: "Excelente / como novo" },
    { value: "good",      label: "Bom" },
    { value: "fair",      label: "Razoável" },
    { value: "worn",      label: "Desgastado" },
    { value: "replaced",  label: "Substituído" },
];

export default function InteriorFurnitureAccordion({ accordionId }: AccordionProps) {
    const { values, setFieldValue } = useFormikContext<ICarUpdatePayload>();
    const inf = values.vehicle_attributes?.interior_furniture;
    // Tapa-luz movidos do Chassis para junto dos remifront (decisão UX Matilde,
    // 2026-06-22). Chaves JSON permanecem em `chassis_structure.*` — só a
    // apresentação muda. Mesmo padrão da sub-fase C do Lote 3.
    const cs = values.vehicle_attributes?.chassis_structure;

    return (
        <AccordionItem id={`hab-acc-${accordionId}`}>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-layout-3-line me-2" />Mobiliário Interior</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                {/* 2026-06-28 — `has_rotating_seats` movido para o accordion
                    "Cabine" (accordionId=10) por decisão UX Matilde: bancos
                    giratórios são da CABINE do veículo, não da célula
                    habitacional. Chave JSON `interior_furniture.has_rotating_seats`
                    permanece intacta. Ver CLAUDE.md sec 6.1. */}
                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_foldable_table"
                            label="Mesa rebatível"
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
                    {/* M2.5 — Guarda-fatos. Mobiliário típico de
                        autocaravana, encaixa naturalmente nesta Row. */}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_wardrobe"
                            label="Guarda-fatos"
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
                    {/* 2026-06-29 — TVs condicionais a has_tv=true (padrão
                        has_fridge → fridge_type/litres). Localização é texto
                        livre por decisão Matilde (múltiplas TVs/sítios). */}
                    {inf?.has_tv && (
                        <>
                            <Col lg={2}>
                                <XInput
                                    type="number"
                                    name="vehicle_attributes.interior_furniture.tv_count"
                                    label="Quantidade"
                                    className="mb-3"
                                />
                            </Col>
                            <Col lg={3}>
                                <XInput
                                    name="vehicle_attributes.interior_furniture.tv_location"
                                    label="Localização"
                                    className="mb-3"
                                />
                            </Col>
                        </>
                    )}
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.interior_furniture.has_command_panel"
                            label="Painel de comandos"
                            className="mb-3"
                        />
                    </Col>
                </Row>

                {/* Lote 3 — claraboias movidas do Chassis. Chaves JSON
                    permanecem em `chassis_structure.has_*_skylight` /
                    `other_skylights_notes` para NÃO partir dados em prod
                    (CLAUDE.md sec 6.1 documenta o desalinhamento intencional). */}
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

                {/* 2026-06-28 — `has_remifront` movido para o accordion
                    "Cabine" (accordionId=10) e renomeado para "Estores Remifront"
                    (decisão UX Matilde). Chave JSON `chassis_structure.has_remifront`
                    permanece intacta. Ver CLAUDE.md sec 6.1. */}
                <Row className="mb-2">
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
                </Row>

                {/* 2026-06-22 — Tapa-luz movidos do Chassis (decisão Matilde —
                    junto aos remifront faz mais sentido UX). Chaves JSON em
                    `chassis_structure.*` permanecem intactas (mesmo padrão dos
                    remifront/skylights da sub-fase C — desalinhamento semântico
                    intencional, ver CLAUDE.md sec 6.1). */}
                <Row className="mb-2">
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
                        <Col lg={3}>
                            <XInput
                                name="vehicle_attributes.chassis_structure.cabin_blackout_type"
                                label="Tipo tapa-luz cabine"
                                className="mb-3"
                            />
                        </Col>
                    )}
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
