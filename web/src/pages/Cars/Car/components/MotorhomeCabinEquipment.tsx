import { useEffect, useMemo, useState } from "react";
import { useFormikContext } from "formik";
import { Accordion, AccordionBody, AccordionHeader, AccordionItem, Col, Input, Label, Row } from "reactstrap";
import XSelect from "Components/Common/Select";
import { gearCountOptions, transmissionOptions } from "common/data/cars";
import type { ICarUpdatePayload } from "common/models/car.model";
import {
    MOTORHOME_CABIN_LIST,
    isCabinItemChecked,
    otherCatalogExtras,
    otherMarkedExtras,
    setOtherExtra,
    toggleCabinItem,
    type CabinListItem,
    type ExtrasByGroup,
    type OtherExtra,
} from "../data/motorhomeCabinList";

type FormValues = ICarUpdatePayload & { extrasByGroup: ExtrasByGroup };

const otherKey = (o: OtherExtra) => `${o.group}::${o.text}`;

/** Quantos itens da lista estão marcados (para o cabeçalho do acordeão). */
export const countCheckedCabinItems = (extrasByGroup: ExtrasByGroup | undefined, vehicleAttributes: unknown): number =>
    MOTORHOME_CABIN_LIST.filter((item) => isCabinItemChecked(item, extrasByGroup, vehicleAttributes)).length;

/**
 * Caixas de visto de uma lista de itens (a da cabine ou a do Exterior): marcar e desmarcar
 * gravam nos extras e atributos de cada item (ver toggleCabinItem).
 */
export function CabinItemChecklist({ items, idPrefix }: { items: CabinListItem[]; idPrefix: string }) {
    const { values, setFieldValue } = useFormikContext<FormValues>();
    const extrasByGroup = values.extrasByGroup;
    const vehicleAttributes = values.vehicle_attributes;

    const onToggleItem = (item: CabinListItem, checked: boolean) => {
        const result = toggleCabinItem(item, checked, extrasByGroup);
        setFieldValue("extrasByGroup", result.extrasByGroup);
        Object.entries(result.attributes).forEach(([path, value]) => {
            setFieldValue(`vehicle_attributes.${path}`, value);
        });
    };

    return (
        <div className="row">
            {items.map((item) => {
                const inputId = `${idPrefix}-${item.id}`;
                return (
                    <div key={item.id} className="col-12 col-md-6 col-lg-4 col-xl-3 mb-2">
                        <div className="form-check">
                            <Input
                                id={inputId}
                                type="checkbox"
                                className="form-check-input"
                                checked={isCabinItemChecked(item, extrasByGroup, vehicleAttributes)}
                                onChange={(e) => onToggleItem(item, e.target.checked)}
                            />
                            <Label className="form-check-label" htmlFor={inputId}>{item.label}</Label>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export interface MotorhomeCabinEquipmentProps {
    /** "Outros equipamentos" aberto (controlado pelo pai, para a pesquisa o poder abrir). */
    othersOpen: boolean;
    onToggleOthers: () => void;
    /** id do AccordionItem de "Outros equipamentos" (a pesquisa encontra-o por aqui). */
    othersDomId: string;
}

/**
 * Lista única "Cabine de condução e segurança" das autocaravanas: a Caixa (os mesmos
 * campos Transmissão e Número de velocidades de "Dados da viatura"), as caixas de visto
 * da lista e, por baixo, o grupo recolhido "Outros equipamentos". Grava nos mesmos
 * extras e atributos de sempre (ver data/motorhomeCabinList.ts).
 */
export default function MotorhomeCabinEquipment({ othersOpen, onToggleOthers, othersDomId }: MotorhomeCabinEquipmentProps) {
    const { values, setFieldValue, setFieldTouched } = useFormikContext<FormValues>();
    const extrasByGroup = values.extrasByGroup;

    // "Outros equipamentos": os marcados fora da lista. Um item desmarcado fica à vista
    // (desmarcado) até sair da página, para se poder voltar a marcar.
    const marked = useMemo(() => otherMarkedExtras(extrasByGroup), [extrasByGroup]);
    const [shown, setShown] = useState<OtherExtra[]>([]);
    useEffect(() => {
        setShown((prev) => {
            const known = new Set(prev.map(otherKey));
            const added = marked.filter((o) => !known.has(otherKey(o)));
            return added.length ? [...prev, ...added] : prev;
        });
    }, [marked]);
    const markedKeys = useMemo(() => new Set(marked.map(otherKey)), [marked]);
    const shownKeys = useMemo(() => new Set(shown.map(otherKey)), [shown]);

    const addOptions = useMemo(
        () => otherCatalogExtras()
            .filter((o) => !shownKeys.has(otherKey(o)))
            .map((o) => ({ value: otherKey(o), label: o.text }))
            .sort((a, b) => a.label.localeCompare(b.label, "pt")),
        [shownKeys],
    );

    const onSetOther = (other: OtherExtra, checked: boolean) => {
        setFieldValue("extrasByGroup", setOtherExtra(extrasByGroup, other, checked));
    };

    const gearValue = values.gear_count === null || values.gear_count === undefined || values.gear_count === ""
        ? null
        : String(values.gear_count);

    return (
        <div>
            <div className="mb-3">
                <Label className="form-label" for="cabin_transmission">Caixa</Label>
                <Row className="g-2">
                    <Col xs={12} sm={6} lg={4} xl={3}>
                        <XSelect
                            id="cabin_transmission"
                            ariaLabel="Transmissão"
                            placeholder="Transmissão"
                            options={transmissionOptions}
                            value={values.transmission || null}
                            onChange={(v) => {
                                setFieldValue("transmission", v || null);
                                setFieldTouched("transmission", true);
                            }}
                        />
                    </Col>
                    <Col xs={12} sm={6} lg={4} xl={3}>
                        <XSelect
                            id="cabin_gear_count"
                            ariaLabel="Número de velocidades"
                            placeholder="Número de velocidades"
                            options={gearCountOptions}
                            value={gearValue}
                            onChange={(v) => {
                                setFieldValue("gear_count", v === "" ? "" : Number(v));
                                setFieldTouched("gear_count", true);
                            }}
                        />
                    </Col>
                </Row>
            </div>

            <CabinItemChecklist items={MOTORHOME_CABIN_LIST} idPrefix="cabin-item" />

            <Accordion flush open={othersOpen ? "others" : ""} toggle={() => onToggleOthers()} className="mt-2 border rounded">
                <AccordionItem id={othersDomId}>
                    <AccordionHeader targetId="others">
                        <div className="d-flex justify-content-between w-100 pe-3">
                            <strong>Outros equipamentos</strong>
                            <span className="text-muted">{marked.length}</span>
                        </div>
                    </AccordionHeader>
                    <AccordionBody accordionId="others">
                        {shown.length === 0 ? (
                            <p className="text-muted mb-3">Não há equipamentos marcados fora da lista.</p>
                        ) : (
                            <div className="row">
                                {shown.map((other, index) => {
                                    const inputId = `cabin-other-${index}`;
                                    return (
                                        <div key={otherKey(other)} className="col-12 col-md-6 col-lg-4 col-xl-3 mb-2">
                                            <div className="form-check">
                                                <Input
                                                    id={inputId}
                                                    type="checkbox"
                                                    className="form-check-input"
                                                    checked={markedKeys.has(otherKey(other))}
                                                    onChange={(e) => onSetOther(other, e.target.checked)}
                                                />
                                                <Label className="form-check-label" htmlFor={inputId}>{other.text}</Label>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        <Row>
                            <Col xs={12} md={6} lg={4}>
                                <Label className="form-label" for="cabin_add_other">Acrescentar equipamento</Label>
                                <XSelect
                                    id="cabin_add_other"
                                    placeholder="Escolha um equipamento do catálogo"
                                    options={addOptions}
                                    value={null}
                                    onChange={(key) => {
                                        const other = otherCatalogExtras().find((o) => otherKey(o) === key);
                                        if (other) onSetOther(other, true);
                                    }}
                                />
                            </Col>
                        </Row>
                    </AccordionBody>
                </AccordionItem>
            </Accordion>
        </div>
    );
}
