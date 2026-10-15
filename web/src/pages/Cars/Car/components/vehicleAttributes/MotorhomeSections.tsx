import type { ReactNode } from "react";
import { AccordionBody, AccordionHeader, AccordionItem, Col, Row } from "reactstrap";
import { useFormikContext } from "formik";
import XInput from "Components/Common/XInput";
import XInputCheckboxArray from "Components/Common/XInputCheckboxArray";
import type { ICarUpdatePayload } from "common/models/car.model";
import { MOTORHOME_CABIN_LIST, MOTORHOME_JANTES, type ExtrasByGroup } from "../../data/motorhomeCabinList";
import MotorhomeCabinEquipment, { countCheckedCabinItems } from "../MotorhomeCabinEquipment";
import { EnergyClimateFields } from "./EnergyClimateAccordion";
import { LivingRoomFields } from "./LivingRoomAccordion";
import { CabinBlackoutFields, InfiltrationFields, InteriorFields, TableUpholsteryFields, WindowFields } from "./InteriorFurnitureAccordion";
import { CabinFields } from "./CabinAccordion";
import { ChassisStructureFields } from "./ChassisStructureAccordion";
import { SecurityFields } from "./SecurityAccordion";
import { ExteriorFields } from "./ExteriorAccordion";
import KitchenFields from "./KitchenFields";
import BathroomFields from "./BathroomFields";

/**
 * Ids dos acordeões das autocaravanas (`hab-acc-{id}` no DOM; a pesquisa do formulário
 * usa-os). "Outros equipamentos" fica dentro da "Cabine de condução e segurança".
 */
export const MOTORHOME_SECTION = {
    energy: "m-energy",
    livingKitchen: "m-living",
    bathroom: "m-bath",
    cabin: "m-cabin",
    others: "m-others",
    exterior: "m-exterior",
} as const;

const SubTitle = ({ icon, first, children }: { icon: string; first?: boolean; children: ReactNode }) => (
    <>
        {!first && <hr className="my-3" />}
        <h6 className="mb-3"><i className={`${icon} me-2`} />{children}</h6>
    </>
);

export interface MotorhomeSectionsProps {
    othersOpen: boolean;
    onToggleOthers: () => void;
}

/**
 * Ficha das autocaravanas (parte A): as secções depois de "Dimensões e Pesos", pela ordem
 * Energia e autonomia; Sala e cozinha; Duche e WC; Cabine de condução e segurança;
 * Exterior. Só muda a apresentação: cada campo grava na chave de sempre. As caravanas
 * mantêm os acordeões de hoje.
 */
export default function MotorhomeSections({ othersOpen, onToggleOthers }: MotorhomeSectionsProps) {
    const { values } = useFormikContext<ICarUpdatePayload & { extrasByGroup: ExtrasByGroup }>();
    const checkedCount = countCheckedCabinItems(values.extrasByGroup, values.vehicle_attributes);

    return (
        <>
            <AccordionItem id={`hab-acc-${MOTORHOME_SECTION.energy}`}>
                <AccordionHeader targetId={MOTORHOME_SECTION.energy}>
                    <strong><i className="ri-flashlight-line me-2" />Energia e autonomia</strong>
                </AccordionHeader>
                <AccordionBody accordionId={MOTORHOME_SECTION.energy}>
                    <Row className="mb-2">
                        <Col lg={2}>
                            <XInput
                                type="number"
                                name="vehicle_attributes.autonomy_km"
                                label="Autonomia (km)"
                                className="mb-3"
                            />
                        </Col>
                    </Row>
                    <EnergyClimateFields />
                </AccordionBody>
            </AccordionItem>

            <AccordionItem id={`hab-acc-${MOTORHOME_SECTION.livingKitchen}`}>
                <AccordionHeader targetId={MOTORHOME_SECTION.livingKitchen}>
                    <strong><i className="ri-sofa-line me-2" />Sala e cozinha</strong>
                </AccordionHeader>
                <AccordionBody accordionId={MOTORHOME_SECTION.livingKitchen}>
                    <SubTitle icon="ri-sofa-line" first>Sala</SubTitle>
                    <LivingRoomFields />
                    <TableUpholsteryFields />
                    <SubTitle icon="ri-restaurant-line">Cozinha</SubTitle>
                    <KitchenFields />
                    <SubTitle icon="ri-layout-3-line">Interior</SubTitle>
                    <InteriorFields />
                    <SubTitle icon="ri-window-line">Janelas</SubTitle>
                    <WindowFields includeCabinBlackouts={false} />
                    <SubTitle icon="ri-drop-line">Infiltrações</SubTitle>
                    <InfiltrationFields />
                </AccordionBody>
            </AccordionItem>

            <AccordionItem id={`hab-acc-${MOTORHOME_SECTION.bathroom}`}>
                <AccordionHeader targetId={MOTORHOME_SECTION.bathroom}>
                    <strong><i className="ri-showers-line me-2" />Duche e WC</strong>
                </AccordionHeader>
                <AccordionBody accordionId={MOTORHOME_SECTION.bathroom}>
                    <BathroomFields />
                </AccordionBody>
            </AccordionItem>

            <AccordionItem id={`hab-acc-${MOTORHOME_SECTION.cabin}`}>
                <AccordionHeader targetId={MOTORHOME_SECTION.cabin}>
                    <div className="d-flex justify-content-between w-100 pe-3">
                        <strong><i className="ri-steering-2-line me-2" />Cabine de condução e segurança</strong>
                        <span className="text-muted">{checkedCount}/{MOTORHOME_CABIN_LIST.length}</span>
                    </div>
                </AccordionHeader>
                <AccordionBody accordionId={MOTORHOME_SECTION.cabin}>
                    <SubTitle icon="ri-steering-2-line" first>Cabine</SubTitle>
                    <CabinFields />
                    <CabinBlackoutFields />
                    <SubTitle icon="ri-tools-line">Chassis</SubTitle>
                    <ChassisStructureFields />
                    <SubTitle icon="ri-lock-line">Fechaduras</SubTitle>
                    <SecurityFields />
                    <SubTitle icon="ri-shield-check-line">Equipamento</SubTitle>
                    <MotorhomeCabinEquipment
                        othersOpen={othersOpen}
                        onToggleOthers={onToggleOthers}
                        othersDomId={`hab-acc-${MOTORHOME_SECTION.others}`}
                    />
                </AccordionBody>
            </AccordionItem>

            <AccordionItem id={`hab-acc-${MOTORHOME_SECTION.exterior}`}>
                <AccordionHeader targetId={MOTORHOME_SECTION.exterior}>
                    <strong><i className="ri-road-map-line me-2" />Exterior</strong>
                </AccordionHeader>
                <AccordionBody accordionId={MOTORHOME_SECTION.exterior}>
                    <Row className="mb-2">
                        <Col lg={2}>
                            {/* O extra de sempre (grupo e texto iguais); não aparece em "Outros equipamentos". */}
                            <XInputCheckboxArray
                                name={`extrasByGroup.${MOTORHOME_JANTES.group}`}
                                value={MOTORHOME_JANTES.text}
                                label="Jantes de liga leve"
                                id="motorhome-alloy-wheels"
                                className="mb-3"
                            />
                        </Col>
                    </Row>
                    <ExteriorFields />
                </AccordionBody>
            </AccordionItem>
        </>
    );
}
