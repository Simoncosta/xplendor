import { useEffect, useMemo, useRef, useState } from "react";
import { useFormikContext } from "formik";
import XInputCheckboxArray from "Components/Common/XInputCheckboxArray";
import type { ICarUpdatePayload, CarExtraGroup, CarExtrasGroup } from "common/models/car.model";
import { Accordion, AccordionBody, AccordionHeader, AccordionItem, Input } from 'reactstrap';
import { EXTRA_GROUPS } from "../data/extraGroups";

type FormValues = ICarUpdatePayload & {
    // UI state (map) usado pelos checkboxes
    extrasByGroup: Record<CarExtraGroup, string[]>;
};

const GROUP_KEYS: CarExtraGroup[] = [
    "comfort_multimedia",
    "exterior_equipment",
    "interior_equipment",
    "safety_performance",
];

// converte map -> array (payload)
const mapToArray = (map: Record<CarExtraGroup, string[]>): CarExtrasGroup[] =>
    GROUP_KEYS.map((group) => ({ group, items: map?.[group] ?? [] }));

const emptyExtrasByGroup: Record<CarExtraGroup, string[]> = {
    comfort_multimedia: [],
    exterior_equipment: [],
    interior_equipment: [],
    safety_performance: [],
};

const arrayToMap = (arr?: CarExtrasGroup[]) => {
    const map: Record<CarExtraGroup, string[]> = {
        ...emptyExtrasByGroup,
    };

    (arr ?? []).forEach((g) => {
        map[g.group] = g.items ?? [];
    });

    return map;
};

export default function CarEquipmentDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue } = useFormikContext<FormValues>();

    const [open, setOpen] = useState<string>("");

    const toggle = (id: string) => {
        setOpen(open === id ? "" : id);
    };

    // Lote 3 — busca client-side por grupo. Filtra apenas o que é exibido,
    // NÃO toca em `extrasByGroup` — items já seleccionados permanecem
    // seleccionados mesmo quando escondidos pelo filtro. Preserva clusters
    // semânticos do `safety_performance` (ESP/EDS/MSR juntos) — escolha
    // deliberada de NÃO alfabetizar.
    const [searchByGroup, setSearchByGroup] = useState<Record<CarExtraGroup, string>>({
        comfort_multimedia: "",
        exterior_equipment: "",
        interior_equipment: "",
        safety_performance: "",
    });
    const setGroupSearch = (g: CarExtraGroup, v: string) =>
        setSearchByGroup((prev) => ({ ...prev, [g]: v }));

    const isSyncingFromExtras = useRef(false);

    useEffect(() => {
        if ((values.extras?.length ?? 0) === 0) return;
        const hasAnyUI = values.extrasByGroup &&
            Object.values(values.extrasByGroup).some((items) => (items?.length ?? 0) > 0);
        if (!hasAnyUI) {
            isSyncingFromExtras.current = true;
            setFieldValue("extrasByGroup", arrayToMap(values.extras as any), false);
        }
    }, [values.extras]);

    useEffect(() => {
        if (!values.extrasByGroup) return;
        if (isSyncingFromExtras.current) {
            isSyncingFromExtras.current = false;
            return; // ignora este ciclo — foi iniciado pelo efeito 1
        }
        setFieldValue("extras", mapToArray(values.extrasByGroup), false);
    }, [values.extrasByGroup]);

    const counts = useMemo(() => {
        const map = new Map<CarExtraGroup, number>();
        EXTRA_GROUPS.forEach((g) => {
            map.set(g.key, (values.extrasByGroup?.[g.key] ?? []).length);
        });
        return map;
    }, [values.extrasByGroup]);

    return (
        <div className="mt-4">
            <Accordion flush open={open} toggle={toggle}>

                {EXTRA_GROUPS.map((group, index) => {
                    const selectedCount = counts.get(group.key) ?? 0;
                    const total = group.items.length;
                    const id = String(index + 1);
                    const search = searchByGroup[group.key] ?? "";
                    const normSearch = search.trim().toLowerCase();
                    const visibleItems = normSearch.length === 0
                        ? group.items
                        : group.items.filter((item) => item.toLowerCase().includes(normSearch));

                    return (
                        <AccordionItem key={group.key}>
                            <AccordionHeader targetId={id}>
                                <div className="d-flex justify-content-between w-100 pe-3">
                                    <strong>{group.title}</strong>
                                    <span className="text-muted">{selectedCount}/{total}</span>
                                </div>
                            </AccordionHeader>

                            <AccordionBody accordionId={id}>
                                {/* Lote 3 — busca por grupo (client-side). NÃO altera
                                    state de selecção; items marcados que ficam fora
                                    do filtro continuam guardados. */}
                                <div className="mb-3">
                                    <Input
                                        type="search"
                                        bsSize="sm"
                                        placeholder={`Procurar em ${group.title.toLowerCase()}…`}
                                        value={search}
                                        onChange={(e) => setGroupSearch(group.key, e.target.value)}
                                    />
                                    {normSearch.length > 0 && (
                                        <small className="text-muted d-block mt-1">
                                            A mostrar {visibleItems.length} de {total} (selecção preservada para os escondidos).
                                        </small>
                                    )}
                                </div>
                                <div className="row">
                                    {visibleItems.map((item) => (
                                        <div key={item} className="col-12 col-md-6 col-lg-4 col-xl-3 mb-2">
                                            <XInputCheckboxArray
                                                name={`extrasByGroup.${group.key}`}
                                                value={item}
                                                label={item}
                                            />
                                        </div>
                                    ))}
                                </div>
                            </AccordionBody>
                        </AccordionItem>
                    );
                })}

            </Accordion>
        </div>
    );
}