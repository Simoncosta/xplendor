import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Spinner } from "reactstrap";
import { getCarPrintSheet } from "helpers/laravel_helper";
import {
    labelOf,
    FUEL_TYPE_LABELS,
    TRANSMISSION_LABELS,
    CONDITION_LABELS,
    ORIGIN_LABELS,
    EXTERIOR_COLOR_LABELS,
} from "helpers/labels";
import { BED_LABELS, type BedType } from "./data/vehicleAttributes";
import type { CarPrintSheet as CarPrintSheetType } from "types/api";

/**
 * Ficha de impressão A4 de viatura (2026-06-27, Fase 1).
 *
 * A cliente (Matilde, autocaravanas) imprime esta ficha e cola nas viaturas
 * do stand. Layout: cabeçalho + faixa de dados-chave + 2 colunas de grupos
 * de características + rodapé com contactos. Uma página A4 por viatura, SEM
 * foto da viatura.
 *
 * Técnica: HTML + @media print + @page A4 + `window.print()` do browser.
 * Sem `jspdf`, `react-to-print`, `puppeteer`, etc.
 *
 * Cores nesta fase são fixas (verde escuro/verde vivo), isoladas em CSS
 * variables no topo → Fase 2 (configuráveis pela empresa) não requer
 * refactor. `print-color-adjust: exact` força os browsers a imprimirem as
 * faixas coloridas.
 *
 * Overflow A4: `column-count:2 + break-inside:avoid` mantém grupos inteiros;
 * se uma viatura muito equipada não couber, cai para 2ª página em vez de
 * espremer. Fase 2 pode adicionar zoom-fit se justificar.
 */

// ═══════════════ Cores (Fase 1 = fixas; Fase 2 = configuráveis) ══════════════
const COLORS = {
    headerBg:      "#12332a", // verde escuro (cabeçalho)
    headerText:    "#ffffff",
    accentBg:      "#0a8a5a", // verde vivo (faixa de dados-chave)
    accentText:    "#ffffff",
    groupTitle:    "#0a8a5a",
    body:          "#1f2937",
    muted:         "#6b7280",
    ruleLight:     "#e5e7eb",
};

const currency = (value: number) =>
    new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);

const num = (value: number | null | undefined, suffix = "") =>
    value == null || Number.isNaN(Number(value)) ? "—" : `${value}${suffix}`;

const numFmt = (value: number | null | undefined, suffix = "") =>
    value == null || Number.isNaN(Number(value))
        ? "—"
        : `${new Intl.NumberFormat("pt-PT").format(Number(value))}${suffix}`;

// ─────────────────────── Helpers de vehicle_attributes ───────────────────────

type VA = Record<string, any>;

/** Devolve rótulo pt-PT do enum quando marcado, senão null. */
const enumLabel = (value: string | null | undefined, map: Record<string, string>): string | null => {
    if (!value || value === "none") return null;
    return map[value] ?? value;
};

const FRIDGE_TYPE_LABELS: Record<string, string> = {
    trivalent:  "trivalente",
    compressor: "compressor",
    absorption: "absorção",
};

const SHOWER_TYPE_LABELS: Record<string, string> = {
    separate:    "cabine dentro do WC",
    independent: "cabine independente",
    combined:    "combinado (sem cabine)",
};

const HEATER_SOURCE_LABELS: Record<string, string> = {
    electric: "elétrico",
    gas:      "gás",
    diesel:   "diesel",
};

const INVERTER_TYPE_LABELS: Record<string, string> = {
    pure_sine:     "onda pura",
    modified_sine: "onda modificada",
};

const CHASSIS_TYPE_LABELS: Record<string, string> = {
    standard: "standard",
    alko:     "Alko",
    other:    "outro",
};

const UPHOLSTERY_LABELS: Record<string, string> = {
    excellent: "excelente",
    good:      "bom",
    fair:      "razoável",
    worn:      "desgastado",
    replaced:  "substituído",
};

const LIVING_ROOM_LAYOUT_LABELS: Record<string, string> = {
    face_to_face:   "Face to Face",
    l_shape:        "sala em L",
    panoramic:      "panorâmica",
    double_dinette: "sala dupla",
};

// ─────────────────────────── Builders de linha ───────────────────────────────

/** Coleciona items truthy num array de strings; ignora null/undefined/false. */
const collect = (...items: (string | null | false | undefined)[]): string[] =>
    items.filter((x): x is string => typeof x === "string" && x.length > 0);

/** 2026-06-28 — capitaliza primeira letra dos items para leitura uniforme
 *  na ficha ("Fogão · Forno" em vez de "fogão · Forno"). Aplica-se ao 1º
 *  caractere apenas; palavras seguintes ficam intactas (nomes de marcas,
 *  siglas WC/LED/TV, etc). */
const cap = (s: string): string => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
const capAll = (items: string[]): string[] => items.map(cap);

interface Group {
    title: string;
    items: string[];
}

const buildKitchen = (va: VA): Group => {
    const k = va?.habitation_basics?.kitchen ?? {};
    const items: string[] = [];
    if (k.has_stove)           items.push("fogão");
    if (k.has_oven)            items.push("forno");
    if (k.has_extending_counter) items.push("acrescento de banca");
    if (k.has_microwave)       items.push("micro-ondas");
    if (k.has_extractor)       items.push("exaustor");
    if (k.has_fridge) {
        const type = enumLabel(k.fridge_type, FRIDGE_TYPE_LABELS);
        const litres = k.fridge_litres ? `${k.fridge_litres} L` : null;
        const parts = collect("Frigorífico", type, litres);
        items.push(parts.join(" "));
    }
    return { title: "Cozinha", items: capAll(items) };
};

const buildBathroom = (va: VA): Group => {
    const b = va?.habitation_basics?.bathroom ?? {};
    const items: string[] = [];
    if (b.has_toilet) items.push("WC");
    if (b.has_shower) {
        const type = enumLabel(b.shower_type, SHOWER_TYPE_LABELS);
        items.push(collect("Duche", type ? `(${type})` : null).join(" "));
    }
    if (b.clean_water_litres) items.push(`Águas limpas ${b.clean_water_litres} L`);
    if (b.waste_water_litres) items.push(`Águas residuais ${b.waste_water_litres} L`);
    return { title: "Casa de banho", items: capAll(items) };
};

const buildEnergy = (va: VA): Group => {
    const e = va?.energy_climate ?? {};
    const items: string[] = [];
    if (e.has_solar_panel) {
        const count = e.solar_panel_count ? `${e.solar_panel_count}×` : "";
        const watts = e.solar_panel_watts ? ` ${e.solar_panel_watts} W` : "";
        items.push(`Painel solar ${count}${watts}`.trim());
    }
    if (e.has_inverter) {
        const type = enumLabel(e.inverter_type, INVERTER_TYPE_LABELS);
        const watts = e.inverter_watts ? `${e.inverter_watts} W` : null;
        items.push(collect("Inversor", type, watts).join(" "));
    }
    if (e.has_gpl) {
        const bottles = e.gpl_bottles_count ? `(${e.gpl_bottles_count} garrafas)` : null;
        items.push(collect("GPL", bottles).join(" "));
    }
    if (e.has_generator)             items.push("Gerador");
    if (e.has_external_power_socket) items.push("Ficha 220V exterior");
    if (e.battery_count)             items.push(`${e.battery_count} bateria(s)`);
    if (e.cabin_battery_count)       items.push(`${e.cabin_battery_count} bat. cabine`);
    if (e.cell_battery_count)        items.push(`${e.cell_battery_count} bat. célula`);
    if (e.has_battery_cutoff)        items.push("Corta-corrente");
    if (e.has_aircon_220v) {
        const brand = e.aircon_220v_brand ? `(${e.aircon_220v_brand})` : null;
        items.push(collect("Ar condicionado 220V", brand).join(" "));
    }
    if (e.has_viesa) items.push("VIESA");
    return { title: "Energia", items: capAll(items) };
};

const buildHeating = (va: VA): Group => {
    const e = va?.energy_climate ?? {};
    const items: string[] = [];
    const water = enumLabel(e.water_heater_source, HEATER_SOURCE_LABELS);
    if (water) {
        const brand = e.water_heater_brand ? ` (${e.water_heater_brand})` : "";
        items.push(`Termoacumulador ${water}${brand}`);
    }
    const amb = enumLabel(e.ambient_heating_source, HEATER_SOURCE_LABELS);
    if (amb) {
        const brand = e.ambient_heating_brand ? ` (${e.ambient_heating_brand})` : "";
        items.push(`Aquecimento ambiente ${amb}${brand}`);
    }
    return { title: "Aquecimento", items: capAll(items) };
};

const buildExterior = (va: VA): Group => {
    const ext = va?.exterior ?? {};
    const items: string[] = [];
    if (ext.has_awning) {
        const brand = ext.awning_brand ? ` (${ext.awning_brand})` : "";
        items.push(`Toldo${brand}`);
    }
    if (ext.has_national_antenna)   items.push("Antena nacional");
    if (ext.has_parabolic_antenna)  items.push("Antena parabólica");
    if (ext.has_bike_rack)          items.push("Porta-bicicletas");
    if (ext.has_motorbike_rack)     items.push("Porta-motos");
    if (ext.has_electric_step)      items.push("Degrau eléctrico");
    if (ext.has_manual_step)        items.push("Degrau manual");
    if (ext.has_stabilizers)        items.push("Macacos estabilizadores");
    if (ext.has_spare_wheel)        items.push("Pneu suplente");
    if (ext.has_fix_n_go_kit)       items.push("Kit Fix'n'Go");
    if (ext.has_bull_eye)           items.push("Olho de boi");
    if (ext.has_external_wc)        items.push("WC exterior");
    if (ext.has_hubcaps)            items.push("Tampas de roda");
    if (ext.has_external_ladder)    items.push("Escada exterior");
    const g = ext.garage ?? {};
    if (g.has_garage) {
        const parts: string[] = [];
        if (g.has_double_opening)   parts.push("dupla abertura");
        if (g.is_spacious)          parts.push("espaçosa");
        if (g.has_height_adjuster)  parts.push("altura ajustável");
        items.push(collect("Garagem", parts.length ? `(${parts.join(", ")})` : null).join(" "));
    }
    return { title: "Exterior", items: capAll(items) };
};

const buildChassis = (va: VA): Group => {
    const cs = va?.chassis_structure ?? {};
    const items: string[] = [];
    const type = enumLabel(cs.chassis_type, CHASSIS_TYPE_LABELS);
    if (type) items.push(`Chassis ${type}`);
    if (cs.has_air_suspension) {
        items.push(cs.has_air_suspension_compressor ? "Suspensão pneumática c/ compressor" : "Suspensão pneumática");
    }
    if (cs.has_dual_rear_wheel)       items.push("Rodado duplo");
    if (cs.has_turbovent_skylight)    items.push("Clarabóia turbovent");
    if (cs.has_panoramic_skylight)    items.push("Clarabóia panorâmica");
    if (cs.has_40x40_skylight)        items.push("Clarabóia 40×40");
    if (cs.other_skylights_notes)     items.push(String(cs.other_skylights_notes));
    if (cs.has_remifront)             items.push("Remifront");
    if (cs.has_mosquito_nets)         items.push("Mosquiteiras janelas");
    if (cs.has_door_mosquito_net)     items.push("Mosquiteira porta");
    if (cs.has_window_blackouts)      items.push("Tapa-luz janelas");
    if (cs.has_cabin_blackouts) {
        items.push(cs.cabin_blackout_type ? `Tapa-luz cabine (${cs.cabin_blackout_type})` : "Tapa-luz cabine");
    }
    return { title: "Chassis e claraboias", items: capAll(items) };
};

const buildInterior = (va: VA): Group => {
    const inf = va?.interior_furniture ?? {};
    const lr = va?.living_room ?? {};
    const items: string[] = [];
    const layout = enumLabel(lr.layout, LIVING_ROOM_LAYOUT_LABELS);
    if (layout) items.push(`Sala: ${layout}`);
    if (lr.has_extending_table)  items.push("Mesa telescópica");
    if (inf.has_foldable_table)  items.push("Mesa rebatível");
    if (inf.has_rotating_seats)  items.push("Bancos giratórios");
    if (inf.has_curtains)        items.push("Cortinas");
    if (inf.has_wardrobe)        items.push("Guarda-fatos");
    if (inf.has_led_lighting)    items.push("Iluminação LED");
    if (inf.has_halo_lighting)   items.push("Iluminação halo");
    if (inf.has_tv_support)      items.push("Suporte TV");
    if (inf.has_tv)              items.push("TV");
    if (inf.has_command_panel)   items.push("Painel de comandos");
    const upho = enumLabel(inf.upholstery_state, UPHOLSTERY_LABELS);
    if (upho) items.push(`Estofos: ${upho}`);
    return { title: "Interior", items: capAll(items) };
};

const buildSecurity = (va: VA): Group => {
    const s = va?.security ?? {};
    const items: string[] = [];
    if (s.has_alarm)          items.push("Alarme");
    if (s.has_hatch_lock)     items.push("Fechadura escotilha");
    if (s.has_cabin_lock)     items.push("Fechadura cabine");
    if (s.has_safe_door)      items.push("Porta cofre");
    if (s.has_gas_lock)       items.push("Fechadura gás");
    if (s.has_entry_door_lock) items.push("Fechadura porta entrada");
    if (s.other_locks_notes)   items.push(String(s.other_locks_notes));
    return { title: "Segurança", items: capAll(items) };
};

const buildBeds = (va: VA): Group => {
    const beds = Array.isArray(va?.beds) ? va.beds : [];
    const items = beds
        .map((b: any) => {
            const type = b?.type as BedType | undefined;
            const label = type && BED_LABELS[type] ? BED_LABELS[type] : "cama";
            const cap = b?.capacity ? ` (dorme ${b.capacity})` : "";
            return `${label}${cap}`;
        })
        .filter(Boolean);
    return { title: "Camas", items: capAll(items) };
};

// ─────────────────────────── Blocos factuais (sempre visíveis) ─────────────
//
// 2026-06-28 (pedido Matilde) — separação em duas categorias:
//   (A) Dados FACTUAIS: sempre visíveis com "—" quando o campo não está
//       preenchido. Razão: se o campo desaparece silenciosamente, a Matilde
//       não sabe se a ficha esqueceu ou se ninguém registou. O "—" é
//       honesto e sinaliza para preencher.
//   (B) Características OPCIONAIS (has_*): mantêm-se condicionais — não
//       mostrar "Toldo: Não", encheria a folha de negativos.
//
// Helper: força labels "Nome VALOR" com fallback "—".

const DASH = "—";
const fmtNum = (v: number | null | undefined, suffix = "") =>
    v == null || Number.isNaN(Number(v)) ? DASH : `${new Intl.NumberFormat("pt-PT").format(Number(v))}${suffix}`;
const fmtDec = (v: number | null | undefined, decimals: number, suffix = "") =>
    v == null || Number.isNaN(Number(v))
        ? DASH
        : `${Number(v).toFixed(decimals).replace(".", ",")}${suffix}`;
const fmtOr = (v: string | null | undefined) => (v && String(v).trim().length ? String(v) : DASH);
const kv = (label: string, value: string) => `${label} ${value}`;

/** Categoria (A) — Dimensões: comprimento, largura, altura. Sempre visíveis. */
const buildDimensions = (va: VA): Group => {
    const d = va?.dimensions ?? {};
    return {
        title: "Dimensões",
        items: [
            kv("Comprimento", fmtDec(d.length_m, 2, " m")),
            kv("Largura",     fmtDec(d.width_m, 2, " m")),
            kv("Altura",      fmtDec(d.height_m, 2, " m")),
        ],
    };
};

/** Categoria (A) — Motor e ficha: todos os campos factuais SEMPRE visíveis. */
const buildEngineBlock = (data: CarPrintSheetType): Group => {
    const isCaravan = data.vehicle_type === "caravan";
    const fuel  = labelOf(data.specs.fuel_type,    FUEL_TYPE_LABELS)      ?? DASH;
    const trans = labelOf(data.specs.transmission, TRANSMISSION_LABELS)   ?? DASH;
    const color = labelOf(data.specs.exterior_color, EXTERIOR_COLOR_LABELS);
    const colorLabel = color
        ? (data.specs.is_metallic ? `${color} metalizado` : color)
        : DASH;

    const items: string[] = [];
    // Motor: caravanas NÃO têm motor — omitir as 4 linhas de motor.
    if (!isCaravan) {
        items.push(kv("Marca do motor", fmtOr(data.engine_brand)));
        items.push(kv("Combustível",    fuel));
        items.push(kv("Transmissão",    trans));
        items.push(kv("Cilindrada",     fmtNum(data.specs.engine_capacity_cc, " cc")));
        items.push(kv("Potência",       fmtNum(data.specs.power_hp, " cv")));
    }
    // `data.specs.doors` NÃO renderizado nesta ficha (2026-06-28) — ruído
    // técnico para autocaravanas; com o princípio "factuais mostram —" ficaria
    // "Portas —" uma linha inteira a dizer nada quando o campo está vazio.
    // Campo mantido intacto em toda a app (form, CarSpecsResource, API pública) —
    // é só não o mostrar aqui. Não requer separador extra: o `.join(" · ")`
    // no render trata dos separadores a partir do array final.
    if (data.vehicle_type !== "motorhome" && data.vehicle_type !== "caravan") {
        items.push(kv("Segmento", fmtOr(data.specs.segment)));
    }
    items.push(kv("Cor exterior", colorLabel));
    if (data.specs.interior_color) {
        items.push(kv("Cor interior", data.specs.interior_color));
    }
    return { title: "Motor e ficha", items };
};

/** Categoria (A) — Estado: condição e origem sempre visíveis. */
/** Retoma/chave/manuais mantêm-se opcionais (B) — só se true. */
const buildStateBlock = (data: CarPrintSheetType): Group => {
    const cond   = labelOf(data.state.condition, CONDITION_LABELS) ?? DASH;
    const origin = labelOf(data.state.origin,    ORIGIN_LABELS)    ?? DASH;
    const items: string[] = [
        kv("Condição", cap(cond)),
        kv("Origem",   cap(origin)),
    ];
    if (data.state.is_trade_in)   items.push("Retoma");
    if (data.state.has_spare_key) items.push("Chave reserva");
    if (data.state.has_manuals)   items.push("Manuais");
    return { title: "Estado", items };
};

// ─────────────────────────── Componente ─────────────────────────────────────

const publicUrl = process.env.REACT_APP_PUBLIC_URL ?? "";

export default function CarPrintSheet() {
    const { companyId, id } = useParams();
    const navigate = useNavigate();
    const [data, setData] = useState<CarPrintSheetType | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    // 2026-06-28 — fallback textual quando a logo falha a carregar (URL
    // errada, ficheiro apagado, etc). Sem isto o browser mostra ícone
    // partido no cabeçalho (crítico — é a única exigência da cliente).
    const [logoBroken, setLogoBroken] = useState(false);

    const goBack = () => {
        // Preferência: voltar à Ficha da viatura (mantém contexto natural).
        // Fallback: window.history se o utilizador entrou directamente por URL.
        if (id) {
            navigate(`/cars/${id}/ficha`);
        } else {
            window.history.back();
        }
    };

    useEffect(() => {
        document.title = "Ficha para impressão | Xplendor";
        if (!companyId || !id) return;
        setLoading(true);
        setError(false);
        getCarPrintSheet(Number(companyId), Number(id))
            .then((res: any) => setData(res?.data ?? null))
            .catch(() => setError(true))
            .finally(() => setLoading(false));
    }, [companyId, id]);

    const groups = useMemo<Group[]>(() => {
        if (!data) return [];
        const va = data.vehicle_attributes ?? {};
        const isHabitation = data.vehicle_type === "motorhome" || data.vehicle_type === "caravan";

        // Categoria (A) — sempre visíveis (mostram "—" quando null).
        const factualGroups: Group[] = [
            buildEngineBlock(data),
            buildStateBlock(data),
        ];
        if (isHabitation) {
            // Dimensões só faz sentido para habitação (autocaravana / caravana).
            factualGroups.unshift(buildDimensions(va));
        }

        // Categoria (B) — opcionais (só se tiverem items).
        const optionalGroups: Group[] = isHabitation
            ? [
                buildKitchen(va),
                buildBathroom(va),
                buildEnergy(va),
                buildHeating(va),
                buildExterior(va),
                buildChassis(va),
                buildInterior(va),
                buildSecurity(va),
                buildBeds(va),
              ]
            : [];

        // Extras do form (checkboxes): concatena todas as strings marcadas.
        const extraItems = (data.extras ?? []).flatMap((g) => g.items ?? []);
        const extrasGroup: Group | null = extraItems.length > 0
            ? { title: "Extras", items: capAll(extraItems) }
            : null;

        // Ordem final: factuais primeiro, depois opcionais habitação, depois
        // extras. Só se filtram os OPCIONAIS vazios — os factuais aparecem
        // sempre por definição.
        const result: Group[] = [
            ...factualGroups,
            ...optionalGroups.filter((g) => g.items.length > 0),
        ];
        if (extrasGroup) result.push(extrasGroup);
        return result;
    }, [data]);

    if (loading) {
        return (
            <div className="d-flex justify-content-center align-items-center" style={{ minHeight: "60vh" }}>
                <Spinner color="primary" />
            </div>
        );
    }

    if (error || !data) {
        return (
            <div className="text-center text-muted py-5">
                Ficha indisponível.
            </div>
        );
    }

    // Formatação do preço com regra "Sob consulta" da CarPublicResource.
    const priceGross = data.price.promo_gross ?? data.price.gross;
    const priceLabel =
        data.price.hide_price_online || !priceGross ? "Sob consulta" : currency(priceGross);
    const priceSuffix = !data.price.hide_price_online && priceGross ? "c/ IVA" : null;

    const logoUrl = data.company.logo_path
        ? (data.company.logo_path.startsWith("http") ? data.company.logo_path : publicUrl + data.company.logo_path)
        : null;

    const identity = [
        data.brand?.name,
        data.model?.name,
        data.version,
    ].filter(Boolean).join(" ");
    const categoryOrType = data.category?.name
        ?? (data.vehicle_type === "motorhome" ? "Autocaravana"
            : data.vehicle_type === "caravan" ? "Caravana"
            : data.vehicle_type === "motorcycle" ? "Mota"
            : "Automóvel");
    const yearText = data.registration.year ? ` · ${data.registration.year}` : "";

    // ── Faixa de dados-chave ─────────────────────────────────────────────────
    const stats = data.headline_stats;
    const showSleeps = data.vehicle_type === "motorhome" || data.vehicle_type === "caravan";
    const showLength = showSleeps;
    const showWeight = showSleeps;
    const headlineCells: Array<{ label: string; value: string }> = [];
    if (stats.seats != null) headlineCells.push({ label: "Lugares", value: numFmt(stats.seats) });
    if (showSleeps)          headlineCells.push({ label: "Dormidas", value: numFmt(stats.sleeps) });
    if (showLength)          headlineCells.push({ label: "Comprimento", value: stats.length_m ? `${stats.length_m.toFixed(2).replace(".", ",")} m` : "—" });
    if (showWeight)          headlineCells.push({ label: "Peso bruto", value: stats.gross_weight_kg ? `${numFmt(stats.gross_weight_kg)} kg` : "—" });
    headlineCells.push({ label: "Km", value: numFmt(stats.mileage_km) });

    return (
        <>
            <style>{PRINT_STYLES}</style>

            {/*
                Overlay full-screen fixed — cobre o painel do Velzon (sidebar +
                cabeçalho + rodapé) que estava a tapar o topo da ficha e a
                deixar a toolbar invisível. Fix P1+P2 do reporte Simon
                (2026-06-28). @media print oculta este overlay wrapper e a
                ficha imprime normalmente.
            */}
            <div className="print-overlay">
                {/* Barra de acções — sticky no topo do overlay para ser SEMPRE
                    visível; @media print esconde via .no-print. */}
                <div className="print-toolbar no-print">
                    <div className="print-toolbar-inner">
                        <button
                            type="button"
                            className="btn btn-light"
                            onClick={goBack}
                        >
                            <i className="ri-arrow-left-line me-1" /> Voltar
                        </button>
                        <div className="print-toolbar-hint">
                            Pré-visualização da ficha para impressão. Clica <strong>Imprimir</strong> para escolher impressora ou guardar como PDF.
                        </div>
                        <button
                            type="button"
                            className="btn btn-primary"
                            onClick={() => window.print()}
                        >
                            <i className="ri-printer-line me-1" /> Imprimir
                        </button>
                    </div>
                </div>

                <div className="print-sheet">
                    {/* Cabeçalho */}
                    <header className="ps-header">
                        <div className="ps-header-left">
                            {logoUrl && !logoBroken ? (
                                <img
                                    src={logoUrl}
                                    alt={data.company.trade_name ?? ""}
                                    className="ps-logo"
                                    onError={() => setLogoBroken(true)}
                                />
                            ) : (
                                <div className="ps-logo-fallback">{data.company.trade_name ?? data.company.fiscal_name ?? "STAND"}</div>
                            )}
                            <div className="ps-identity">
                                <div className="ps-model">{identity || "Viatura"}</div>
                                <div className="ps-sub">{categoryOrType}{yearText}</div>
                            </div>
                        </div>
                        <div className="ps-header-right">
                            <div className="ps-price">{priceLabel}</div>
                            {priceSuffix && <div className="ps-price-suffix">{priceSuffix}</div>}
                        </div>
                    </header>

                {/* Faixa de dados-chave */}
                <div className="ps-headline">
                    {headlineCells.map((c) => (
                        <div key={c.label} className="ps-headline-cell">
                            <div className="ps-headline-label">{c.label}</div>
                            <div className="ps-headline-value">{c.value}</div>
                        </div>
                    ))}
                </div>

                {/* Grupos em 2 colunas */}
                <div className="ps-groups">
                    {groups.map((g) => (
                        <section key={g.title} className="ps-group">
                            <h3 className="ps-group-title">{g.title}</h3>
                            <p className="ps-group-body">
                                {g.items.join(" · ")}
                            </p>
                        </section>
                    ))}
                </div>

                {/* Rodapé — colado ao fundo da folha via `.ps-groups { flex: 1 }`.
                    Disclaimer legal (texto fixo pt-PT, igual para todas as empresas)
                    + linha de identificação da empresa (dinâmica) + matrícula +
                    garantia. Sem website (decisão Simon 2026-06-28). */}
                <footer className="ps-footer">
                    <p className="ps-disclaimer">
                        Este preçário serve apenas como uma prévia do veículo e não faz parte de um contrato de venda.
                        As informações fornecidas aqui são descrições não vinculativas e não representam bens garantidos.
                        O vendedor não se responsabiliza por erros, erros de introdução e erros de transmissão de dados.
                    </p>
                    <div className="ps-footer-identity">
                        {[
                            data.company.fiscal_name ?? data.company.trade_name,
                            data.company.address,
                            data.company.postal_code,
                            data.company.phone || data.company.mobile,
                            data.company.email,
                        ].filter(Boolean).join(" · ")}
                    </div>
                    {/* Categoria (A) — Garantia SEMPRE visível (pedido Matilde
                        2026-06-28). Matrícula continua condicional (é factual
                        mas discreta; ficha faz sentido sem ela para viaturas
                        sem matrícula portuguesa). */}
                    <div className="ps-footer-meta">
                        {data.license_plate ? <span>Matrícula {data.license_plate}</span> : null}
                        <span className="ps-warranty">
                            Garantia · {data.warranty_months ? `${data.warranty_months} meses` : DASH}
                        </span>
                    </div>
                </footer>
                </div>
            </div>
        </>
    );
}

// ─────────────────────────────── Estilo A4 ───────────────────────────────────
// Isolado numa string para poder ser inline no <style> — reduz risco de o CSS
// do painel (sidebar, navbar, cards) vazar para a impressão. `.no-print` esconde
// a barra de acções na impressão.
const PRINT_STYLES = `
@page {
    size: A4 portrait;
    margin: 12mm 12mm 10mm 12mm;
}

/* Faixas coloridas: força os browsers a imprimirem os backgrounds. */
.print-sheet * {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

/* Overlay full-screen fixed — cobre o painel do Velzon por completo (fix
   P1+P2 do reporte Simon 2026-06-28). Assim a toolbar deixa de ser tapada
   pelo cabeçalho fixo do painel e a folha A4 aparece centrada, com scroll
   próprio, sem margens fantasmas do .page-content. */
.print-overlay {
    position: fixed;
    inset: 0;
    z-index: 1500;
    background: #f3f4f6;
    overflow-y: auto;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: ${COLORS.body};
}

/* Toolbar sticky no topo do overlay — SEMPRE visível durante scroll do
   preview. Fundo branco + sombra para se destacar. */
.print-toolbar {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #ffffff;
    border-bottom: 1px solid #e5e7eb;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
}
.print-toolbar-inner {
    max-width: 1000px;
    margin: 0 auto;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.print-toolbar-hint {
    flex: 1;
    font-size: 12px;
    color: #6b7280;
    text-align: center;
}
@media (max-width: 640px) {
    .print-toolbar-hint { display: none; }
}

/* Folha A4 — layout flex column para colar o rodapé ao fundo (2026-06-28).
   ".ps-groups { flex: 1 }" expande a área de grupos, empurrando o footer
   contra a margem inferior. Assim: viatura pouco equipada = espaço em cima
   dos grupos vai crescer suavemente; muito equipada = grupos ocupam tudo,
   footer permanece colado no fim sem overflow. */
.print-sheet {
    display: flex;
    flex-direction: column;
}
@media screen {
    .print-sheet {
        width: 210mm;
        min-height: 297mm;
        margin: 20px auto;
        padding: 12mm 12mm 10mm 12mm;
        background: #fff;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.10);
    }
}
@media print {
    .print-sheet {
        /* Em impressão, a folha ocupa a página completa; "min-height" em cm
           garante que o flex-1 empurra o rodapé para o fundo do papel. */
        min-height: calc(297mm - 22mm); /* A4 menos margens do @page */
    }
}
@media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    /* O overlay deixa de ser fixed em impressão para não cortar em 1 página. */
    .print-overlay {
        position: static !important;
        overflow: visible !important;
        background: #fff !important;
        inset: auto !important;
    }
    .print-sheet {
        width: 100%;
        margin: 0;
        padding: 0;
        box-shadow: none;
    }
    /* Esconde tudo o que possa vir do layout do painel (sidebar, navbar, back-to-top). */
    .vertical-menu, .app-menu, .navbar-header, .page-title-box, .footer, #back-to-top { display: none !important; }
}

/* ── Cabeçalho ──────────────────────────────────────────────────────────── */
.ps-header {
    background: ${COLORS.headerBg};
    color: ${COLORS.headerText};
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 18px;
    border-radius: 8px 8px 0 0;
}
.ps-header-left { display: flex; align-items: center; gap: 14px; }
.ps-logo { max-height: 44px; max-width: 130px; background: #fff; padding: 4px 6px; border-radius: 4px; }
.ps-logo-fallback { font-weight: 700; letter-spacing: 0.05em; }
.ps-model { font-size: 20px; font-weight: 700; line-height: 1.1; }
.ps-sub { font-size: 12px; opacity: 0.85; letter-spacing: 0.04em; text-transform: uppercase; margin-top: 2px; }
.ps-header-right { text-align: right; }
.ps-price { font-size: 30px; font-weight: 800; line-height: 1; }
.ps-price-suffix { font-size: 11px; opacity: 0.85; letter-spacing: 0.06em; text-transform: uppercase; margin-top: 3px; }

/* ── Faixa de dados-chave ───────────────────────────────────────────────── */
.ps-headline {
    background: ${COLORS.accentBg};
    color: ${COLORS.accentText};
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    border-radius: 0 0 8px 8px;
    margin-bottom: 14px;
}
.ps-headline-cell {
    padding: 10px 8px;
    text-align: center;
    border-right: 1px solid rgba(255, 255, 255, 0.18);
}
.ps-headline-cell:last-child { border-right: none; }
.ps-headline-label { font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.9; }
.ps-headline-value { font-size: 18px; font-weight: 700; margin-top: 2px; }

/* ── Grupos em 2 colunas ────────────────────────────────────────────────── */
/* flex: 1 empurra o rodapé contra o fundo da folha (2026-06-28). */
.ps-groups {
    column-count: 2;
    column-gap: 16px;
    flex: 1;
}
/* Estirado moderadamente (2026-06-28): +respiro entre grupos, tipografia
   ligeiramente maior. Margem de segurança conservadora — testado
   mentalmente numa viatura com 9 grupos cheios + 30+ extras: continua
   a caber em A4. Se o Simon reportar overflow, reduzir font-size a 12px
   e margin-bottom dos grupos a 12px. */
.ps-group {
    break-inside: avoid;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid ${COLORS.ruleLight};
}
.ps-group-title {
    font-size: 11.5px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: ${COLORS.groupTitle};
    margin: 0 0 4px 0;
    font-weight: 700;
}
.ps-group-body {
    margin: 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: ${COLORS.body};
}

/* ── Rodapé ─────────────────────────────────────────────────────────────── */
/* Sem flex-grow no rodapé — a expansão vive em .ps-groups. */
.ps-footer {
    margin-top: 14px;
    padding-top: 10px;
    border-top: 2px solid ${COLORS.headerBg};
    color: ${COLORS.muted};
}
/* Disclaimer — texto pequeno mas legível; text-align: justify uniformiza
   as linhas para leitura ao pé da autocaravana. */
.ps-disclaimer {
    margin: 0 0 8px 0;
    font-size: 9px;
    line-height: 1.45;
    text-align: justify;
    color: ${COLORS.muted};
}
.ps-footer-identity {
    font-size: 10.5px;
    font-weight: 600;
    color: ${COLORS.body};
    padding-top: 6px;
    border-top: 1px solid ${COLORS.ruleLight};
}
.ps-footer-meta {
    margin-top: 6px;
    display: flex;
    gap: 14px;
    font-size: 10px;
    color: ${COLORS.muted};
    justify-content: flex-end;
}
.ps-warranty { color: ${COLORS.groupTitle}; font-weight: 700; }
`;
