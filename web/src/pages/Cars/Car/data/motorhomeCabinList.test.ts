import { EXTRA_GROUPS } from "./extraGroups";
import {
    MOTORHOME_CABIN_LIST,
    MOTORHOME_NEW_EXTRA_TEXTS,
    isCabinItemChecked,
    otherCatalogExtras,
    otherMarkedExtras,
    setOtherExtra,
    toggleCabinItem,
    type CabinTarget,
    type ExtrasByGroup,
} from "./motorhomeCabinList";
import { filterIndexByVehicleType, getFormSearchIndex } from "./formSearchIndex";

const empty = (): ExtrasByGroup => ({
    comfort_multimedia: [],
    exterior_equipment: [],
    interior_equipment: [],
    safety_performance: [],
});

const item = (id: string) => {
    const found = MOTORHOME_CABIN_LIST.find((i) => i.id === id);
    if (!found) throw new Error(`item ${id} não existe`);
    return found;
};

const NEW_TEXTS = Object.values(MOTORHOME_NEW_EXTRA_TEXTS) as string[];

describe("lista única: o mapa", () => {
    it("tem os rótulos da Quebom, por esta ordem (a Caixa vem antes, fora da lista)", () => {
        expect(MOTORHOME_CABIN_LIST.map((i) => i.label)).toEqual([
            "ISOFIX", "Ar condicionado", "Câmara de marcha atrás", "Sensores de marcha atrás", "Rádio", "Rádio Android",
            "Ecrã tátil", "Rádio digital (DAB)", "Alarme", "Travão de mão elétrico", "Cruise control", "ABS", "ASR", "EBD",
            "Airbag do condutor", "Airbag do passageiro", "Vidros elétricos", "Retrovisores elétricos", "Retrovisores aquecidos",
            "Direção assistida", "Bancos giratórios", "Controlo de descida (Hill Descent Control)", "Comandos no volante",
            "Sensores de chuva", "Traction+", "Start and stop", "Perfis de condutor", "Deteção de cansaço e fadiga",
            "Câmara frontal", "Aviso de velocidade", "Alerta de cinto de segurança", "Bancos com memória",
            "Volante multifunções", "Apoio de braço", "Bancos com regulação elétrica", "Bancos rebatíveis",
            "Bancos aquecidos", "Patilhas de velocidade no volante", "Bancos desportivos", "Carregador de smartphone",
            "Faróis diurnos", "Gancho de reboque", "Fecho centralizado", "Retrovisor antiencandeamento",
            "Faróis de nevoeiro", "Faróis reguláveis em altura", "Bluetooth", "Entrada USB",
        ]);
        expect(new Set(MOTORHOME_CABIN_LIST.map((i) => i.id)).size).toBe(MOTORHOME_CABIN_LIST.length);
    });

    it("grava nos textos de hoje: cada extra existe tal e qual no catálogo, no mesmo grupo (exceto os 3 textos novos)", () => {
        const targets: CabinTarget[] = MOTORHOME_CABIN_LIST.flatMap((i) => [i.target, ...(i.variants ?? [])]);
        for (const t of targets) {
            if (t.kind !== "extra" || NEW_TEXTS.includes(t.text)) continue;
            const group = EXTRA_GROUPS.find((g) => g.key === t.group);
            expect(group?.items).toContain(t.text);
        }
    });

    it("os textos novos são os do contrato e não entram no catálogo dos carros", () => {
        expect(NEW_TEXTS).toEqual(["Rádio Android", "Rádio digital (DAB)", "Carregador de smartphone"]);
        const catalog = EXTRA_GROUPS.flatMap((g) => g.items);
        for (const text of NEW_TEXTS) expect(catalog).not.toContain(text);
        expect(EXTRA_GROUPS.map((g) => g.key)).toEqual([
            "comfort_multimedia", "exterior_equipment", "interior_equipment", "safety_performance",
        ]);
    });

    it("um item aparece uma só vez: Bancos giratórios junta o atributo e os Bancos rotativos; Retrovisores aquecidos não se repete", () => {
        const labels = MOTORHOME_CABIN_LIST.map((i) => i.label);
        expect(labels.filter((l) => l === "Retrovisores aquecidos")).toHaveLength(1);
        expect(labels).not.toContain("Bancos rotativos");
        expect(item("swivel_seats").target).toEqual({ kind: "attribute", path: "interior_furniture.has_rotating_seats" });
        expect(item("swivel_seats").variants).toEqual([{ kind: "extra", group: "interior_equipment", text: "Bancos rotativos" }]);
    });
});

describe("lista única: marcar e desmarcar", () => {
    it("marcar grava o texto principal no grupo do item", () => {
        const r = toggleCabinItem(item("touch_screen"), true, empty());
        expect(r.extrasByGroup.comfort_multimedia).toEqual(["Ecrã Táctil ou Touch Screen"]);
        expect(r.attributes).toEqual({});
    });

    it("uma variante conta como marcado, e desmarcar tira o principal e as variantes", () => {
        const extras = { ...empty(), safety_performance: ["ABS", "Fecho Centralizado com Comando a Distância"] };
        expect(isCabinItemChecked(item("central_locking"), extras, {})).toBe(true);

        const marked = toggleCabinItem(item("central_locking"), true, extras);
        expect(marked.extrasByGroup.safety_performance).toEqual(["ABS", "Fecho Centralizado com Comando a Distância", "Fecho Central"]);

        const r = toggleCabinItem(item("central_locking"), false, marked.extrasByGroup);
        expect(r.extrasByGroup.safety_performance).toEqual(["ABS"]);
        expect(isCabinItemChecked(item("central_locking"), r.extrasByGroup, {})).toBe(false);
    });

    it("Faróis diurnos: os de LED contam como marcado e saem ao desmarcar", () => {
        const extras = { ...empty(), exterior_equipment: ["Faróis Diurnos Em Led", "Jantes de Liga Leve"] };
        expect(isCabinItemChecked(item("daytime_lights"), extras, {})).toBe(true);
        const r = toggleCabinItem(item("daytime_lights"), false, extras);
        expect(r.extrasByGroup.exterior_equipment).toEqual(["Jantes de Liga Leve"]);
    });

    it("Alarme: grava no atributo; o extra Alarme conta como marcado e sai ao desmarcar", () => {
        const extras = { ...empty(), safety_performance: ["Alarme"] };
        expect(isCabinItemChecked(item("alarm"), extras, { security: { has_alarm: false } })).toBe(true);
        expect(isCabinItemChecked(item("alarm"), empty(), { security: { has_alarm: true } })).toBe(true);

        const on = toggleCabinItem(item("alarm"), true, empty());
        expect(on.attributes).toEqual({ "security.has_alarm": true });
        expect(on.extrasByGroup).toEqual(empty());

        const off = toggleCabinItem(item("alarm"), false, extras);
        expect(off.attributes).toEqual({ "security.has_alarm": false });
        expect(off.extrasByGroup.safety_performance).toEqual([]);
    });

    it("Bancos giratórios: o extra Bancos rotativos conta como marcado; desmarcar tira-o e desliga o atributo", () => {
        const extras = { ...empty(), interior_equipment: ["Bancos rotativos", "Apoio de Braço"] };
        expect(isCabinItemChecked(item("swivel_seats"), extras, {})).toBe(true);
        const off = toggleCabinItem(item("swivel_seats"), false, extras);
        expect(off.attributes).toEqual({ "interior_furniture.has_rotating_seats": false });
        expect(off.extrasByGroup.interior_equipment).toEqual(["Apoio de Braço"]);
    });

    it("Carregador de smartphone: grava o texto novo; os textos sem fios contam como marcado", () => {
        const on = toggleCabinItem(item("smartphone_charger"), true, empty());
        expect(on.extrasByGroup.interior_equipment).toEqual(["Carregador de smartphone"]);
        const wireless = { ...empty(), comfort_multimedia: ["Carregamento Wireless"] };
        expect(isCabinItemChecked(item("smartphone_charger"), wireless, {})).toBe(true);
        expect(toggleCabinItem(item("smartphone_charger"), false, wireless).extrasByGroup.comfort_multimedia).toEqual([]);
    });

    it("um texto marcado noutro grupo conta e não se duplica ao marcar", () => {
        const extras = { ...empty(), safety_performance: ["Ar condicionado"] };
        expect(isCabinItemChecked(item("air_conditioning"), extras, {})).toBe(true);
        const r = toggleCabinItem(item("air_conditioning"), true, extras);
        expect(r.extrasByGroup.comfort_multimedia).toEqual([]);
        expect(r.extrasByGroup.safety_performance).toEqual(["Ar condicionado"]);
    });

    it("marcar e desmarcar não mexe nos extras fora da lista nem num grupo desconhecido", () => {
        const extras = {
            ...empty(),
            comfort_multimedia: ["Android Auto", "Computador de Bordo"],
            safety_performance: ["Texto antigo que não está no catálogo"],
            outro_grupo: ["X"],
        } as unknown as ExtrasByGroup;
        const on = toggleCabinItem(item("abs"), true, extras);
        const off = toggleCabinItem(item("abs"), false, on.extrasByGroup);
        expect(off.extrasByGroup).toEqual(extras);
    });
});

describe("Outros equipamentos", () => {
    it("mostra os extras marcados fora da lista (incluindo textos que não estão no catálogo)", () => {
        const extras = {
            ...empty(),
            comfort_multimedia: ["Android Auto", "Bluetooth"],
            interior_equipment: ["Bancos rotativos", "Acabamentos em Madeira"],
            safety_performance: ["Texto antigo"],
        };
        expect(otherMarkedExtras(extras)).toEqual([
            { group: "comfort_multimedia", text: "Android Auto" },
            { group: "interior_equipment", text: "Acabamentos em Madeira" },
            { group: "safety_performance", text: "Texto antigo" },
        ]);
    });

    it("o catálogo para acrescentar não repete o que a lista já tem", () => {
        const texts = otherCatalogExtras().map((o) => o.text);
        expect(texts).toContain("Android Auto");
        expect(texts).toContain("Jantes de Liga Leve");
        expect(texts).not.toContain("ABS");
        expect(texts).not.toContain("Bancos rotativos");
        expect(texts).not.toContain("Faróis Diurnos Em Led");
    });

    it("acrescentar e tirar um extra grava só no seu grupo", () => {
        const added = setOtherExtra(empty(), { group: "comfort_multimedia", text: "Android Auto" }, true);
        expect(added.comfort_multimedia).toEqual(["Android Auto"]);
        expect(setOtherExtra(added, { group: "comfort_multimedia", text: "Android Auto" }, true).comfort_multimedia).toEqual(["Android Auto"]);
        expect(setOtherExtra(added, { group: "comfort_multimedia", text: "Android Auto" }, false)).toEqual(empty());
    });
});

describe("pesquisa do formulário", () => {
    it("nas autocaravanas encontra a lista única e Outros equipamentos; nos outros tipos, os 4 grupos de sempre", () => {
        const index = getFormSearchIndex();
        const motorhome = filterIndexByVehicleType(index, "motorhome");
        const car = filterIndexByVehicleType(index, "car");

        const at = (entries: typeof index, label: string) =>
            entries.filter((e) => e.label === label).map((e) => (e.location.kind === "section" ? e.location.sectionLabel : e.location.accordionLabel));

        expect(at(motorhome, "Ecrã tátil")).toEqual(["Cabine de condução e segurança"]);
        expect(at(motorhome, "ABS")).toEqual(["Cabine de condução e segurança"]);
        expect(at(motorhome, "Android Auto")).toEqual(["Outros equipamentos"]);
        expect(at(motorhome, "Ecrã Táctil ou Touch Screen")).toEqual([]);

        expect(at(car, "ABS")).toEqual(["Segurança & Desempenho"]);
        expect(at(car, "Ecrã Táctil ou Touch Screen")).toEqual(["Conforto & Multimédia"]);
        expect(at(car, "Rádio digital (DAB)")).toEqual([]);
        expect(at(filterIndexByVehicleType(index, "caravan"), "ABS")).toEqual(["Segurança & Desempenho"]);

        // Alarme e Bancos giratórios: na lista única das autocaravanas; nos acordeões das caravanas.
        expect(at(motorhome, "Alarme")).toEqual(["Cabine de condução e segurança"]);
        expect(at(motorhome, "Bancos giratórios")).toEqual(["Cabine de condução e segurança"]);
        const caravan = filterIndexByVehicleType(index, "caravan");
        expect(at(caravan, "Alarme")).toEqual(["Segurança e Fechaduras", "Segurança & Desempenho"]);
        expect(at(caravan, "Bancos giratórios")).toEqual(["Cabine"]);
    });
});
