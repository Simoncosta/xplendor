import type { CarExtraGroup } from "common/models/car.model";
import { EXTRA_GROUPS } from "./extraGroups";

// ═════════════════════════════════════════════════════════════════════════
// Lista única "Cabine de condução e segurança" (só autocaravanas).
// ─────────────────────────────────────────────────────────────────────────
// Muda só a apresentação. Cada item tem o rótulo do ecrã e diz onde grava:
//   - num extra, com o grupo e o TEXTO DE HOJE (o site da Quebom, a análise por
//     IA e o histórico leem os extras pelo texto exato, por isso não muda);
//   - num atributo booleano de `vehicle_attributes`;
//   - num texto novo (os 3 de `MOTORHOME_NEW_EXTRA_TEXTS`, contrato com o site).
// As variantes também contam como marcado. Marcar grava o principal; desmarcar
// tira o principal e todas as variantes.
//
// Os extras marcados que não estão na lista continuam gravados e aparecem no
// grupo "Outros equipamentos". Os carros, as motos e as caravanas mantêm os 4
// grupos de sempre (`EXTRA_GROUPS`).
// ═════════════════════════════════════════════════════════════════════════

export type CabinTarget =
    | { kind: "extra"; group: CarExtraGroup; text: string }
    /** Caminho dentro de `vehicle_attributes` (por exemplo, "security.has_alarm"). */
    | { kind: "attribute"; path: string };

export interface CabinListItem {
    /** Estável: usado nos ids do DOM e nos testes. */
    id: string;
    /** Rótulo do ecrã (português de Portugal). */
    label: string;
    /** Onde grava ao marcar. */
    target: CabinTarget;
    /** Também contam como marcado; desmarcar tira-as. */
    variants?: CabinTarget[];
}

export type ExtrasByGroup = Record<CarExtraGroup, string[]>;

const extra = (group: CarExtraGroup, text: string): CabinTarget => ({ kind: "extra", group, text });
const attribute = (path: string): CabinTarget => ({ kind: "attribute", path });

const C = "comfort_multimedia" as const;
const E = "exterior_equipment" as const;
const I = "interior_equipment" as const;
const S = "safety_performance" as const;

/** Textos novos de extras (contrato com o site da Quebom). */
export const MOTORHOME_NEW_EXTRA_TEXTS = {
    androidRadio: "Rádio Android",
    dabRadio: "Rádio digital (DAB)",
    smartphoneCharger: "Carregador de smartphone",
} as const;

/**
 * A lista, pela ordem da Quebom. A linha "Caixa" (Transmissão e Número de
 * velocidades) fica antes destes itens e não grava em extras.
 */
export const MOTORHOME_CABIN_LIST: CabinListItem[] = [
    { id: "isofix", label: "ISOFIX", target: extra(S, "ISOFIX") },
    { id: "air_conditioning", label: "Ar condicionado", target: extra(C, "Ar condicionado") },
    { id: "rear_camera", label: "Câmara de marcha atrás", target: extra(S, "Câmara de Marcha Atrás") },
    { id: "rear_sensors", label: "Sensores de marcha atrás", target: extra(S, "Sensores de marcha atrás") },
    { id: "radio", label: "Rádio", target: extra(C, "Rádio") },
    { id: "android_radio", label: "Rádio Android", target: extra(C, MOTORHOME_NEW_EXTRA_TEXTS.androidRadio) },
    { id: "touch_screen", label: "Ecrã tátil", target: extra(C, "Ecrã Táctil ou Touch Screen") },
    { id: "dab_radio", label: "Rádio digital (DAB)", target: extra(C, MOTORHOME_NEW_EXTRA_TEXTS.dabRadio) },
    { id: "alarm", label: "Alarme", target: attribute("security.has_alarm"), variants: [extra(S, "Alarme")] },
    { id: "electric_handbrake", label: "Travão de mão elétrico", target: extra(S, "Travão de Mão Eléctrico") },
    { id: "cruise_control", label: "Cruise control", target: extra(S, "Cruise Control") },
    { id: "abs", label: "ABS", target: extra(S, "ABS") },
    { id: "asr", label: "ASR", target: extra(S, "ASR") },
    { id: "ebd", label: "EBD", target: extra(S, "EBD") },
    { id: "driver_airbag", label: "Airbag do condutor", target: extra(S, "Airbag do Condutor") },
    { id: "passenger_airbag", label: "Airbag do passageiro", target: extra(S, "Airbag de Passageiros") },
    { id: "electric_windows", label: "Vidros elétricos", target: extra(C, "Vidros eléctricos") },
    { id: "electric_mirrors", label: "Retrovisores elétricos", target: extra(E, "Retrovisores c/ Regulação Eléctrica") },
    { id: "heated_mirrors", label: "Retrovisores aquecidos", target: extra(E, "Retrovisores Aquecidos") },
    { id: "power_steering", label: "Direção assistida", target: extra(S, "Direcção Assistida") },
    {
        id: "swivel_seats",
        label: "Bancos giratórios",
        target: attribute("interior_furniture.has_rotating_seats"),
        variants: [extra(I, "Bancos rotativos")],
    },
    { id: "hill_descent", label: "Controlo de descida (Hill Descent Control)", target: extra(S, "Hill Descent Control") },
    { id: "steering_controls", label: "Comandos no volante", target: extra(I, "Volante com Comandos de Rádio") },
    { id: "rain_sensors", label: "Sensores de chuva", target: extra(S, "Sensores de Chuva") },
    { id: "traction_plus", label: "Traction+", target: extra(S, "Traction+") },
    { id: "start_stop", label: "Start and stop", target: extra(S, "Start and Stop") },
    { id: "driver_profiles", label: "Perfis de condutor", target: extra(S, "Perfil De Condutores") },
    { id: "fatigue_detection", label: "Deteção de cansaço e fadiga", target: extra(S, "Deteção Cansaço e Fadiga") },
    { id: "front_camera", label: "Câmara frontal", target: extra(S, "Camara de Frente") },
    { id: "speed_warning", label: "Aviso de velocidade", target: extra(S, "Aviso de velocidade") },
    { id: "seatbelt_alert", label: "Alerta de cinto de segurança", target: extra(S, "Alertas sobre Cinto de Segurança") },
    { id: "memory_seats", label: "Bancos com memória", target: extra(I, "Bancos Dianteiros c/ Memória") },
    { id: "multifunction_wheel", label: "Volante multifunções", target: extra(I, "Volante Multifunções") },
    { id: "armrest", label: "Apoio de braço", target: extra(I, "Apoio de Braço") },
    { id: "electric_seats", label: "Bancos com regulação elétrica", target: extra(I, "Bancos Dianteiros c/ Regulação Eléctrica") },
    { id: "folding_seats", label: "Bancos rebatíveis", target: extra(I, "Bancos Rebativeis") },
    { id: "heated_seats", label: "Bancos aquecidos", target: extra(I, "Bancos Dianteiros Aquecidos") },
    { id: "paddle_shifters", label: "Patilhas de velocidade no volante", target: extra(I, "Patilhas de Velocidade no Volante") },
    { id: "sport_seats", label: "Bancos desportivos", target: extra(I, "Bancos Desportivos") },
    {
        id: "smartphone_charger",
        label: "Carregador de smartphone",
        target: extra(I, MOTORHOME_NEW_EXTRA_TEXTS.smartphoneCharger),
        variants: [extra(I, "Carregador de smartphone wireless"), extra(C, "Carregamento Wireless")],
    },
    {
        id: "daytime_lights",
        label: "Faróis diurnos",
        target: extra(E, "Faróis Diurnos"),
        variants: [extra(E, "Faróis Diurnos Em Led")],
    },
    { id: "tow_hook", label: "Gancho de reboque", target: extra(E, "Gancho de Reboque") },
    {
        id: "central_locking",
        label: "Fecho centralizado",
        target: extra(S, "Fecho Central"),
        variants: [extra(S, "Fecho Centralizado com Comando a Distância")],
    },
    {
        id: "anti_glare_mirror",
        label: "Retrovisor antiencandeamento",
        target: extra(E, "Retrovisor Anti-Encadeamento"),
        variants: [extra(E, "Retrovisores c/ Anti Encadeamento")],
    },
    { id: "fog_lights", label: "Faróis de nevoeiro", target: extra(E, "Faróis de Nevoeiro") },
    { id: "height_adjustable_lights", label: "Faróis reguláveis em altura", target: extra(E, "Faróis Reguláveis em Altura") },
    { id: "bluetooth", label: "Bluetooth", target: extra(C, "Bluetooth") },
    { id: "usb", label: "Entrada USB", target: extra(C, "Entrada USB") },
];

/**
 * Extras que, nas autocaravanas, aparecem noutra secção da ficha (e não em
 * "Outros equipamentos").
 */
export const MOTORHOME_EXTRAS_SHOWN_ELSEWHERE: CabinTarget[] = [];

// ─────────────────────────────────────────────────────────────────────────
// Funções puras (testadas em motorhomeCabinList.test.ts).
// ─────────────────────────────────────────────────────────────────────────

const GROUP_KEYS: CarExtraGroup[] = ["comfort_multimedia", "exterior_equipment", "interior_equipment", "safety_performance"];

const readPath = (obj: unknown, path: string): unknown =>
    path.split(".").reduce<any>((cur, key) => (cur == null ? undefined : cur[key]), obj);

/** Copia o mapa sem perder nenhum grupo (nem um que não seja dos 4). */
const cloneExtras = (extrasByGroup: Partial<ExtrasByGroup> | undefined): ExtrasByGroup => {
    const next = { ...(extrasByGroup ?? {}) } as ExtrasByGroup;
    for (const g of GROUP_KEYS) next[g] = [...(extrasByGroup?.[g] ?? [])];
    return next;
};

const itemTargets = (item: CabinListItem): CabinTarget[] => [item.target, ...(item.variants ?? [])];

/** Um texto conta como marcado em qualquer grupo (o site também o lê só pelo texto). */
const hasExtraText = (extrasByGroup: Partial<ExtrasByGroup> | undefined, text: string): boolean =>
    GROUP_KEYS.some((g) => (extrasByGroup?.[g] ?? []).includes(text));

export const isTargetOn = (
    target: CabinTarget,
    extrasByGroup: Partial<ExtrasByGroup> | undefined,
    vehicleAttributes: unknown,
): boolean =>
    target.kind === "extra"
        ? hasExtraText(extrasByGroup, target.text)
        : readPath(vehicleAttributes, target.path) === true;

export const isCabinItemChecked = (
    item: CabinListItem,
    extrasByGroup: Partial<ExtrasByGroup> | undefined,
    vehicleAttributes: unknown,
): boolean => itemTargets(item).some((t) => isTargetOn(t, extrasByGroup, vehicleAttributes));

export interface CabinToggleResult {
    extrasByGroup: ExtrasByGroup;
    /** Atributos a gravar, por caminho dentro de `vehicle_attributes`. */
    attributes: Record<string, boolean>;
}

/**
 * Marcar grava o principal (o texto vai para o grupo do item, se ainda não
 * estiver em nenhum). Desmarcar tira o principal e as variantes, de qualquer
 * grupo. Os outros extras ficam exatamente como estavam.
 */
export const toggleCabinItem = (
    item: CabinListItem,
    checked: boolean,
    extrasByGroup: Partial<ExtrasByGroup> | undefined,
): CabinToggleResult => {
    const next = cloneExtras(extrasByGroup);
    const attributes: Record<string, boolean> = {};

    if (checked) {
        const t = item.target;
        if (t.kind === "attribute") {
            attributes[t.path] = true;
        } else if (!hasExtraText(next, t.text)) {
            next[t.group] = [...next[t.group], t.text];
        }
        return { extrasByGroup: next, attributes };
    }

    const textsToRemove = new Set<string>();
    for (const t of itemTargets(item)) {
        if (t.kind === "attribute") attributes[t.path] = false;
        else textsToRemove.add(t.text);
    }
    for (const g of GROUP_KEYS) {
        next[g] = next[g].filter((text) => !textsToRemove.has(text));
    }
    return { extrasByGroup: next, attributes };
};

/** Todos os textos de extras que a lista (ou outra secção) já mostra. */
export const coveredExtraTexts = (): Set<string> => {
    const out = new Set<string>();
    for (const item of MOTORHOME_CABIN_LIST) {
        for (const t of itemTargets(item)) if (t.kind === "extra") out.add(t.text);
    }
    for (const t of MOTORHOME_EXTRAS_SHOWN_ELSEWHERE) if (t.kind === "extra") out.add(t.text);
    return out;
};

export interface OtherExtra {
    group: CarExtraGroup;
    text: string;
}

/** Extras marcados que não estão na lista: o conteúdo de "Outros equipamentos". */
export const otherMarkedExtras = (extrasByGroup: Partial<ExtrasByGroup> | undefined): OtherExtra[] => {
    const covered = coveredExtraTexts();
    const out: OtherExtra[] = [];
    for (const g of GROUP_KEYS) {
        for (const text of extrasByGroup?.[g] ?? []) {
            if (!covered.has(text)) out.push({ group: g, text });
        }
    }
    return out;
};

/** Extras do catálogo que não estão na lista: os que se podem acrescentar em "Outros equipamentos". */
export const otherCatalogExtras = (): OtherExtra[] => {
    const covered = coveredExtraTexts();
    const out: OtherExtra[] = [];
    for (const group of EXTRA_GROUPS) {
        for (const text of group.items) {
            if (!covered.has(text)) out.push({ group: group.key, text });
        }
    }
    return out;
};

/** Acrescenta ou tira um extra de "Outros equipamentos" (só no seu grupo). */
export const setOtherExtra = (
    extrasByGroup: Partial<ExtrasByGroup> | undefined,
    other: OtherExtra,
    checked: boolean,
): ExtrasByGroup => {
    const next = cloneExtras(extrasByGroup);
    const list = next[other.group];
    next[other.group] = checked
        ? (list.includes(other.text) ? list : [...list, other.text])
        : list.filter((t) => t !== other.text);
    return next;
};
