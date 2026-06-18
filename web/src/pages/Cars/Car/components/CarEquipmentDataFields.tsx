import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from "react";
import { useFormikContext } from "formik";
import XInputCheckboxArray from "Components/Common/XInputCheckboxArray";
import type { ICarUpdatePayload, CarExtraGroup, CarExtrasGroup } from "common/models/car.model";
import { Accordion, AccordionBody, AccordionHeader, AccordionItem } from 'reactstrap';
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

/**
 * Handle exposto ao pai para a busca universal abrir um dos 4 grupos
 * de extras programaticamente. Não interfere com o `toggle` manual.
 */
export interface CarEquipmentHandle {
    openAccordion: (id: string) => void;
}

const CarEquipmentDataFields = forwardRef<CarEquipmentHandle, { isEdit: boolean }>(function CarEquipmentDataFields({ isEdit }, ref) {
    const { values, setFieldValue } = useFormikContext<FormValues>();

    const [open, setOpen] = useState<string>("");

    const toggle = (id: string) => {
        setOpen(open === id ? "" : id);
    };

    // Busca universal — FORÇA aberto. Fechar via openAccordion("").
    useImperativeHandle(ref, () => ({
        openAccordion: (id: string) => setOpen(id),
    }), []);

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
        <div className="mt-4" id="section-extras">
            <Accordion flush open={open} toggle={toggle}>

                {EXTRA_GROUPS.map((group, index) => {
                    const selectedCount = counts.get(group.key) ?? 0;
                    const total = group.items.length;
                    const id = String(index + 1);

                    return (
                        <AccordionItem key={group.key} id={`extras-acc-${id}`}>
                            <AccordionHeader targetId={id}>
                                <div className="d-flex justify-content-between w-100 pe-3">
                                    <strong>{group.title}</strong>
                                    <span className="text-muted">{selectedCount}/{total}</span>
                                </div>
                            </AccordionHeader>

                            <AccordionBody accordionId={id}>
                                <div className="row">
                                    {group.items.map((item) => (
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
});

export default CarEquipmentDataFields;
