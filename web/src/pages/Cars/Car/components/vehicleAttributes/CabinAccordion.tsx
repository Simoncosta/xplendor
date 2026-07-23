import { AccordionBody, AccordionHeader, AccordionItem, Col, Row } from "reactstrap";
import XInputCheckbox from "Components/Common/XInputCheckbox";

/**
 * Accordion "Cabine" (2026-06-28) — equipamentos que dizem respeito ao
 * CARRO/CABINE, não à célula habitacional. Decisão UX Matilde.
 *
 * ⚠️ Desalinhamento intencional (registado em CLAUDE.md sec 6.1): as CHAVES
 * JSON dos 2 campos aqui renderizados permanecem nos sítios originais para
 * NÃO partir dados já preenchidos e evitar migration:
 *   - `chassis_structure.has_remifront` (aparece como "Estores Remifront")
 *   - `interior_furniture.has_rotating_seats` (aparece como "Bancos giratórios")
 *
 * Só a apresentação muda. Padrão idêntico ao dos tapa-luz na 1.14.6 P2 e
 * dos remifront/mosquiteiras na sub-fase C do Lote 3.
 */

export interface AccordionProps {
    accordionId: string;
}

export default function CabinAccordion({ accordionId }: AccordionProps) {
    return (
        <AccordionItem id={`hab-acc-${accordionId}`}>
            <AccordionHeader targetId={accordionId}>
                <strong><i className="ri-steering-2-line me-2" />Cabine</strong>
            </AccordionHeader>
            <AccordionBody accordionId={accordionId}>

                <Row className="mb-2">
                    <Col lg={2}>
                        <XInputCheckbox
                            name="vehicle_attributes.chassis_structure.has_remifront"
                            label="Estores Remifront"
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
                </Row>

            </AccordionBody>
        </AccordionItem>
    );
}
