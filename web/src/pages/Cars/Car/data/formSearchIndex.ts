// ═════════════════════════════════════════════════════════════════════════
// ⚠ ÍNDICE MANUAL DA BUSCA UNIVERSAL DO FORMULÁRIO DE VIATURA ⚠
// ─────────────────────────────────────────────────────────────────────────
// AO ADICIONAR UM CAMPO NOVO AO FORMULÁRIO (qualquer XInput, XInputCheckbox,
// Select, accordion novo, secção nova), ADICIONA-O AQUI — senão a busca
// universal NÃO o encontra e a Matilde volta a perder-se a procurar.
//
// ADICIONALMENTE, anota o filtro por vehicle_type quando o campo é
// CONDICIONAL (só renderiza para certos tipos):
//
//   - As 9 LOCATIONS de habitação (HAB_DIMENSIONS, HAB_KITCHEN, … HAB_LIVING_ROOM)
//     já têm `vehicleTypes: HABITATION_ONLY` — qualquer entrada que usa essa
//     location HERDA automaticamente. Não anotes na entrada.
//   - Campos restritos por outras condições (Combustível/CC/CV/Transmissão
//     escondidos em caravan, Segmento/Cilindros escondidos em motorhome/caravan,
//     Categoria só em motorhome, Marca do motor só em habitação) ganham
//     `vehicleTypes:` próprio na entrada (override da location).
//   - Sem anotação = aplica-se a TODOS os tipos. É o caso da maioria
//     (Marca, Modelo, Ano, Preço, Imagens, Descrição, Status, …).
//
// Os items de extras (~142 entradas em `safety_performance` etc.) NÃO vivem
// aqui — são auto-derivados de `EXTRA_GROUPS` pela função `getFormSearchIndex`
// no fim do ficheiro. Aplicam-se a todos os tipos (sem vehicleTypes).
//
// Não é gerado automaticamente (parser AST seria frágil dada a heterogeneidade
// real do código: <XInput label="X" name="Y">, <Select> + <Label> irmão,
// <XInputCheckbox> idem, items de extras como strings…). A escolha foi
// manual deliberada — fiável, revisível, custa pouca manutenção desde que
// não esqueçamos no code-review.
//
// Documentado no CLAUDE.md sec 10 ("Busca universal do formulário de viatura").
// ═════════════════════════════════════════════════════════════════════════

import { EXTRA_GROUPS } from "./extraGroups";

/** Tipos de viatura suportados (espelha `cars.vehicle_type`). */
export type FormSearchVehicleType = "car" | "motorcycle" | "motorhome" | "caravan";

// Conjuntos prontos para anotar entradas / locations condicionais.
// (Convenção: `vehicleTypes` ausente OU undefined = aplica-se a TODOS.)
const HABITATION_ONLY: FormSearchVehicleType[] = ["motorhome", "caravan"];
const NON_CARAVAN:     FormSearchVehicleType[] = ["car", "motorcycle", "motorhome"];
const NON_HABITATION:  FormSearchVehicleType[] = ["car", "motorcycle"];
const MOTORHOME_ONLY:  FormSearchVehicleType[] = ["motorhome"];

/**
 * Localização de um campo dentro do formulário.
 *
 * - `section`: secção "solta" (não em accordion). Scroll directo por `domId`.
 * - `habitation`: accordion dentro de `CarVehicleDetailsDataFields`.
 *   `accordionId` = "1"..."9" (corresponde ao targetId Reactstrap).
 * - `extras`: accordion dentro de `CarEquipmentDataFields`.
 *   `accordionId` = "1"..."4".
 *
 * Locations podem ter `vehicleTypes?` próprio — entradas herdam-no por
 * defeito (override possível na própria entrada).
 */
export type FormSearchLocation =
    | { kind: "section"; domId: string; sectionLabel: string; vehicleTypes?: FormSearchVehicleType[] }
    | { kind: "habitation"; accordionId: string; accordionLabel: string; vehicleTypes?: FormSearchVehicleType[] }
    | { kind: "extras"; accordionId: string; accordionLabel: string; vehicleTypes?: FormSearchVehicleType[] };

export interface FormSearchEntry {
    /** Texto visível ao utilizador (label do campo). */
    label: string;
    /** Localização para o `useFieldSpotlight` saber para onde levar. */
    location: FormSearchLocation;
    /**
     * Texto normalizado (lowercase + sem acentos) para o filtro `includes`.
     * Pré-computado em `getFormSearchIndex` para não normalizar 260 entradas
     * por keystroke.
     */
    normalizedLabel: string;
    /**
     * `name` do Formik quando aplicável (informativo; útil para v2 do scroll
     * fino até ao campo específico). Para items de extras é null.
     */
    fieldName: string | null;
    /**
     * Tipos de viatura onde este campo aparece. `undefined` = todos.
     * Computado pelo `getFormSearchIndex`: entrada override > location
     * herdada > undefined (todos).
     */
    vehicleTypes?: FormSearchVehicleType[];
}

// ═════════════════════════════════════════════════════════════════════════
// Secções soltas (não em accordion) — scroll directo por `domId`.
// Cada secção corresponde a um <DataFields> em CarEditor.
// ═════════════════════════════════════════════════════════════════════════

const SECTION_INFORMATION = "section-information";   // CarInformationDataFields
const SECTION_VEHICLE     = "section-vehicle";        // CarVehicleDataFields
const SECTION_DETAILS     = "section-details";        // CarVehicleDetailsDataFields (campos base — segment, seats, cor, condition, mileage…)
const SECTION_ADDITIONAL  = "section-additional";     // CarAdditionalDataFields
const SECTION_PRICE       = "section-price";          // CarPriceDataFields
const SECTION_DESCRIPTION = "section-description";    // CarDescriptionDataFields
const SECTION_IMAGES      = "section-images";         // CarImagesDataFields

const LBL_INFO    = "Identificação";
const LBL_VEHICLE = "Dados da Viatura";
const LBL_DETAILS = "Detalhes da Viatura";
const LBL_ADD     = "Dados Adicionais";
const LBL_PRICE   = "Preço";
const LBL_DESC    = "Descrição";
const LBL_IMG     = "Imagens";

// ═════════════════════════════════════════════════════════════════════════
// Accordions de Habitação (CarVehicleDetailsDataFields :256 → AccordionId "1"-"9")
// ═════════════════════════════════════════════════════════════════════════

// Todos os 9 accordions de habitação herdam vehicleTypes: HABITATION_ONLY.
// Entradas que usam estas locations NÃO precisam de anotação repetida.
const HAB_DIMENSIONS  = { kind: "habitation" as const, accordionId: "1", accordionLabel: "Dimensões e Pesos",       vehicleTypes: HABITATION_ONLY };
const HAB_KITCHEN     = { kind: "habitation" as const, accordionId: "2", accordionLabel: "Cozinha",                  vehicleTypes: HABITATION_ONLY };
const HAB_BATHROOM    = { kind: "habitation" as const, accordionId: "3", accordionLabel: "Casa de Banho",            vehicleTypes: HABITATION_ONLY };
const HAB_ENERGY      = { kind: "habitation" as const, accordionId: "4", accordionLabel: "Energia e Aquecimento",    vehicleTypes: HABITATION_ONLY };
const HAB_EXTERIOR    = { kind: "habitation" as const, accordionId: "5", accordionLabel: "Exterior",                 vehicleTypes: HABITATION_ONLY };
const HAB_SECURITY    = { kind: "habitation" as const, accordionId: "6", accordionLabel: "Segurança e Fechaduras",   vehicleTypes: HABITATION_ONLY };
const HAB_CHASSIS     = { kind: "habitation" as const, accordionId: "7", accordionLabel: "Chassis e Estrutura",      vehicleTypes: HABITATION_ONLY };
const HAB_INTERIOR    = { kind: "habitation" as const, accordionId: "8", accordionLabel: "Mobiliário Interior",      vehicleTypes: HABITATION_ONLY };
const HAB_LIVING_ROOM = { kind: "habitation" as const, accordionId: "9", accordionLabel: "Sala",                     vehicleTypes: HABITATION_ONLY };

// ═════════════════════════════════════════════════════════════════════════
// Accordions de Extras (CarEquipmentDataFields → AccordionId "1"-"4")
// ═════════════════════════════════════════════════════════════════════════

const EXTRAS_COMFORT  = { kind: "extras" as const, accordionId: "1", accordionLabel: "Conforto & Multimédia" };
const EXTRAS_EXTERIOR = { kind: "extras" as const, accordionId: "2", accordionLabel: "Equipamento Exterior" };
const EXTRAS_INTERIOR = { kind: "extras" as const, accordionId: "3", accordionLabel: "Equipamento Interior" };
const EXTRAS_SAFETY   = { kind: "extras" as const, accordionId: "4", accordionLabel: "Segurança & Desempenho" };

const EXTRA_GROUP_LOCATION: Record<string, typeof EXTRAS_COMFORT> = {
    comfort_multimedia: EXTRAS_COMFORT,
    exterior_equipment: EXTRAS_EXTERIOR,
    interior_equipment: EXTRAS_INTERIOR,
    safety_performance: EXTRAS_SAFETY,
};

// ═════════════════════════════════════════════════════════════════════════
// Helper: normaliza string para busca (NFD + strip diacritics + lowercase).
// "Águas residuais (L)" → "aguas residuais (l)"
// Permite a Matilde escrever "agua" e encontrar "Água limpa".
// ═════════════════════════════════════════════════════════════════════════
export const normalizeForSearch = (s: string): string =>
    s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

// ═════════════════════════════════════════════════════════════════════════
// MANUAL — campos JSX. AO ADICIONAR CAMPO NOVO, ADICIONA AQUI A LINHA.
// Ordem importa pouco (será re-ordenado por relevância no FormSearchBar);
// mantemos por secção para revisão humana.
// ═════════════════════════════════════════════════════════════════════════

type RawEntry = {
    label: string;
    name: string | null;
    loc: FormSearchLocation;
    /** Override do vehicleTypes da location. Anota AQUI quando a entrada
     *  é restrita apesar da location ser universal (ex.: Segmento, Cilindros,
     *  Categoria, Marca do motor, motor base em não-caravan). */
    vehicleTypes?: FormSearchVehicleType[];
};

const RAW_MANUAL: RawEntry[] = [
    // ── Identificação (CarInformationDataFields) ─────────────────────────
    { label: "Estado",            name: "status",          loc: { kind: "section", domId: SECTION_INFORMATION, sectionLabel: LBL_INFO } },
    { label: "Origem",            name: "origin",          loc: { kind: "section", domId: SECTION_INFORMATION, sectionLabel: LBL_INFO } },
    { label: "Vendedor",          name: "seller_user_id",  loc: { kind: "section", domId: SECTION_INFORMATION, sectionLabel: LBL_INFO } },
    { label: "Tipo de viatura",   name: "vehicle_type",    loc: { kind: "section", domId: SECTION_INFORMATION, sectionLabel: LBL_INFO } },
    { label: "Matrícula",         name: "license_plate",   loc: { kind: "section", domId: SECTION_INFORMATION, sectionLabel: LBL_INFO } },
    { label: "VIN",               name: "vin",             loc: { kind: "section", domId: SECTION_INFORMATION, sectionLabel: LBL_INFO } },

    // ── Dados da Viatura (CarVehicleDataFields) ──────────────────────────
    { label: "Marca",             name: "car_brand_id",        loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE } },
    { label: "Modelo",            name: "car_model_id",        loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE } },
    // engine_brand só em motorhome/caravan (CarVehicleDataFields:59 isHabitationVehicle)
    { label: "Marca do motor",    name: "engine_brand",        loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE }, vehicleTypes: HABITATION_ONLY },
    { label: "Mês de matrícula",  name: "registration_month",  loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE } },
    { label: "Ano",               name: "registration_year",   loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE } },
    // Motor (Combustível/CC/CV/Transmissão) escondido em caravan
    // (CarVehicleDataFields:58 hasMotorFields = type !== "caravan")
    { label: "Combustível",       name: "fuel_type",           loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE }, vehicleTypes: NON_CARAVAN },
    { label: "Capacidade (CC)",   name: "engine_capacity_cc",  loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE }, vehicleTypes: NON_CARAVAN },
    { label: "Potência (CV)",     name: "power_hp",            loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE }, vehicleTypes: NON_CARAVAN },
    { label: "Transmissão",       name: "transmission",        loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE }, vehicleTypes: NON_CARAVAN },
    { label: "Portas",            name: "doors",               loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE } },
    { label: "Versão",            name: "version",             loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE } },
    { label: "Versão (web)",      name: "public_version_name", loc: { kind: "section", domId: SECTION_VEHICLE, sectionLabel: LBL_VEHICLE } },

    // ── Detalhes (CarVehicleDetailsDataFields — campos base fora dos accordions) ──
    // Segmento só em car/motorcycle (CarVehicleDetailsDataFields:162 !hasHabitationAttributes)
    { label: "Segmento",          name: "segment",          loc: { kind: "section", domId: SECTION_DETAILS, sectionLabel: LBL_DETAILS }, vehicleTypes: NON_HABITATION },
    // Categoria só em motorhome (CarVehicleDetailsDataFields:181 type === "motorhome")
    { label: "Categoria",         name: "car_category_id",  loc: { kind: "section", domId: SECTION_DETAILS, sectionLabel: LBL_DETAILS }, vehicleTypes: MOTORHOME_ONLY },
    { label: "Lugares",           name: "seats",            loc: { kind: "section", domId: SECTION_DETAILS, sectionLabel: LBL_DETAILS } },
    { label: "Cor exterior",      name: "exterior_color",   loc: { kind: "section", domId: SECTION_DETAILS, sectionLabel: LBL_DETAILS } },
    { label: "Cor Metálica",      name: "is_metallic",      loc: { kind: "section", domId: SECTION_DETAILS, sectionLabel: LBL_DETAILS } },
    { label: "Estado da viatura", name: "condition",        loc: { kind: "section", domId: SECTION_DETAILS, sectionLabel: LBL_DETAILS } },
    { label: "Quilometragem (km)",name: "mileage_km",       loc: { kind: "section", domId: SECTION_DETAILS, sectionLabel: LBL_DETAILS } },

    // ── Dados Adicionais (CarAdditionalDataFields) ───────────────────────
    { label: "Emissões CO2 (g/km)", name: "co2_emissions", loc: { kind: "section", domId: SECTION_ADDITIONAL, sectionLabel: LBL_ADD } },
    { label: "Classe de portagem", name: "toll_class",      loc: { kind: "section", domId: SECTION_ADDITIONAL, sectionLabel: LBL_ADD } },
    // Cilindros escondido em motorhome/caravan (CarAdditionalDataFields:60 !isMotorhomeOrCaravan)
    { label: "Cilindros",         name: "cylinders",        loc: { kind: "section", domId: SECTION_ADDITIONAL, sectionLabel: LBL_ADD }, vehicleTypes: NON_HABITATION },
    { label: "Tem Chave Reserva", name: "has_spare_key",    loc: { kind: "section", domId: SECTION_ADDITIONAL, sectionLabel: LBL_ADD } },
    { label: "Tem Manual",        name: "has_manuals",      loc: { kind: "section", domId: SECTION_ADDITIONAL, sectionLabel: LBL_ADD } },

    // ── Preço (CarPriceDataFields) ───────────────────────────────────────
    { label: "Preço (€) c/ IVA",  name: "price_gross",        loc: { kind: "section", domId: SECTION_PRICE, sectionLabel: LBL_PRICE } },
    { label: "Preço promo (€)",   name: "promo_price_gross",  loc: { kind: "section", domId: SECTION_PRICE, sectionLabel: LBL_PRICE } },
    { label: "Preço (€) s/ IVA",  name: "price_net",          loc: { kind: "section", domId: SECTION_PRICE, sectionLabel: LBL_PRICE } },
    { label: "Preço sob consulta",name: "hide_price_online",  loc: { kind: "section", domId: SECTION_PRICE, sectionLabel: LBL_PRICE } },

    // ── Descrição (CarDescriptionDataFields) ─────────────────────────────
    { label: "Descrição (PT)",    name: "description_website_pt", loc: { kind: "section", domId: SECTION_DESCRIPTION, sectionLabel: LBL_DESC } },

    // ── Imagens (CarImagesDataFields) ────────────────────────────────────
    { label: "Imagens",           name: "images",           loc: { kind: "section", domId: SECTION_IMAGES, sectionLabel: LBL_IMG } },

    // ── HAB.1 Dimensões e Pesos (CarVehicleDetailsDataFields accordionId="1") ──
    { label: "Comprimento (m)",   name: "vehicle_attributes.dimensions.length_m",      loc: HAB_DIMENSIONS },
    { label: "Largura (m)",       name: "vehicle_attributes.dimensions.width_m",       loc: HAB_DIMENSIONS },
    { label: "Altura (m)",        name: "vehicle_attributes.dimensions.height_m",      loc: HAB_DIMENSIONS },
    { label: "Peso bruto (kg)",   name: "vehicle_attributes.weights.gross_weight_kg",  loc: HAB_DIMENSIONS },
    { label: "Autonomia (km)",    name: "vehicle_attributes.autonomy_km",              loc: HAB_DIMENSIONS },
    { label: "Camas (tipos e capacidade)", name: "vehicle_attributes.beds",            loc: HAB_DIMENSIONS },
    { label: "Dorme (lugares para dormir)",name: "vehicle_attributes.habitation_basics.sleeps", loc: HAB_DIMENSIONS },

    // ── HAB.2 Cozinha (accordionId="2") ──────────────────────────────────
    { label: "Tem cozinha",       name: "vehicle_attributes.habitation_basics.has_kitchen", loc: HAB_KITCHEN },
    { label: "Fogão",             name: "vehicle_attributes.habitation_basics.kitchen.has_stove", loc: HAB_KITCHEN },
    { label: "Forno",             name: "vehicle_attributes.habitation_basics.kitchen.has_oven", loc: HAB_KITCHEN },
    { label: "Acrescento de banca",name: "vehicle_attributes.habitation_basics.kitchen.has_extending_counter", loc: HAB_KITCHEN },
    { label: "Micro-ondas",       name: "vehicle_attributes.habitation_basics.kitchen.has_microwave", loc: HAB_KITCHEN },
    { label: "Exaustor",          name: "vehicle_attributes.habitation_basics.kitchen.has_extractor", loc: HAB_KITCHEN },
    { label: "Frigorífico",       name: "vehicle_attributes.habitation_basics.kitchen.has_fridge", loc: HAB_KITCHEN },
    { label: "Tipo de frigorífico", name: "vehicle_attributes.habitation_basics.kitchen.fridge_type", loc: HAB_KITCHEN },
    { label: "Capacidade do frigorífico (L)", name: "vehicle_attributes.habitation_basics.kitchen.fridge_litres", loc: HAB_KITCHEN },
    { label: "Prateleiras do frigorífico", name: "vehicle_attributes.habitation_basics.kitchen.fridge_shelves", loc: HAB_KITCHEN },

    // ── HAB.3 Casa de Banho (accordionId="3") ────────────────────────────
    { label: "Tem casa de banho", name: "vehicle_attributes.habitation_basics.has_bathroom", loc: HAB_BATHROOM },
    { label: "Sanita",            name: "vehicle_attributes.habitation_basics.bathroom.has_toilet", loc: HAB_BATHROOM },
    { label: "Duche",             name: "vehicle_attributes.habitation_basics.bathroom.has_shower", loc: HAB_BATHROOM },
    { label: "Tipo de duche",     name: "vehicle_attributes.habitation_basics.bathroom.shower_type", loc: HAB_BATHROOM },
    { label: "Água limpa (L)",    name: "vehicle_attributes.habitation_basics.bathroom.clean_water_litres", loc: HAB_BATHROOM },
    { label: "Águas residuais (L)", name: "vehicle_attributes.habitation_basics.bathroom.waste_water_litres", loc: HAB_BATHROOM },

    // ── HAB.4 Energia e Aquecimento (accordionId="4") ────────────────────
    { label: "Aquecimento de água", name: "vehicle_attributes.energy_climate.water_heater_source", loc: HAB_ENERGY },
    { label: "Marca (aquec. água)", name: "vehicle_attributes.energy_climate.water_heater_brand", loc: HAB_ENERGY },
    { label: "Aquecimento ambiente", name: "vehicle_attributes.energy_climate.ambient_heating_source", loc: HAB_ENERGY },
    { label: "Marca (aquec. ambiente)", name: "vehicle_attributes.energy_climate.ambient_heating_brand", loc: HAB_ENERGY },
    { label: "Painel solar",      name: "vehicle_attributes.energy_climate.has_solar_panel", loc: HAB_ENERGY },
    { label: "Quantidade de painéis solares", name: "vehicle_attributes.energy_climate.solar_panel_count", loc: HAB_ENERGY },
    { label: "Potência painel solar (W)", name: "vehicle_attributes.energy_climate.solar_panel_watts", loc: HAB_ENERGY },
    { label: "Inversor/Conversor",name: "vehicle_attributes.energy_climate.has_inverter", loc: HAB_ENERGY },
    { label: "Tipo de inversor",  name: "vehicle_attributes.energy_climate.inverter_type", loc: HAB_ENERGY },
    { label: "Potência inversor (W)", name: "vehicle_attributes.energy_climate.inverter_watts", loc: HAB_ENERGY },
    { label: "Tomada exterior 220V", name: "vehicle_attributes.energy_climate.has_external_power_socket", loc: HAB_ENERGY },
    { label: "GPL",               name: "vehicle_attributes.energy_climate.has_gpl", loc: HAB_ENERGY },
    { label: "Garrafas GPL",      name: "vehicle_attributes.energy_climate.gpl_bottles_count", loc: HAB_ENERGY },
    { label: "Gerador (gasóleo)", name: "vehicle_attributes.energy_climate.has_generator", loc: HAB_ENERGY },
    { label: "Baterias (total)",  name: "vehicle_attributes.energy_climate.battery_count", loc: HAB_ENERGY },
    { label: "Baterias cabine",   name: "vehicle_attributes.energy_climate.cabin_battery_count", loc: HAB_ENERGY },
    { label: "Baterias célula",   name: "vehicle_attributes.energy_climate.cell_battery_count", loc: HAB_ENERGY },
    { label: "Corta-corrente",    name: "vehicle_attributes.energy_climate.has_battery_cutoff", loc: HAB_ENERGY },
    { label: "Ar condicionado 220V", name: "vehicle_attributes.energy_climate.has_aircon_220v", loc: HAB_ENERGY },
    { label: "Marca (A/C 220V)",  name: "vehicle_attributes.energy_climate.aircon_220v_brand", loc: HAB_ENERGY },
    { label: "VIESA",             name: "vehicle_attributes.energy_climate.has_viesa", loc: HAB_ENERGY },

    // ── HAB.5 Exterior (accordionId="5") ─────────────────────────────────
    { label: "Toldo",             name: "vehicle_attributes.exterior.has_awning", loc: HAB_EXTERIOR },
    { label: "Marca do toldo",    name: "vehicle_attributes.exterior.awning_brand", loc: HAB_EXTERIOR },
    { label: "Antena nacional",   name: "vehicle_attributes.exterior.has_national_antenna", loc: HAB_EXTERIOR },
    { label: "Antena parabólica", name: "vehicle_attributes.exterior.has_parabolic_antenna", loc: HAB_EXTERIOR },
    { label: "Suporte de bicicletas", name: "vehicle_attributes.exterior.has_bike_rack", loc: HAB_EXTERIOR },
    { label: "Suporte de mota",   name: "vehicle_attributes.exterior.has_motorbike_rack", loc: HAB_EXTERIOR },
    { label: "Degrau eléctrico",  name: "vehicle_attributes.exterior.has_electric_step", loc: HAB_EXTERIOR },
    { label: "Degrau manual",     name: "vehicle_attributes.exterior.has_manual_step", loc: HAB_EXTERIOR },
    { label: "Pneu suplente",     name: "vehicle_attributes.exterior.has_spare_wheel", loc: HAB_EXTERIOR },
    { label: "Kit Fix&Go",        name: "vehicle_attributes.exterior.has_fix_n_go_kit", loc: HAB_EXTERIOR },
    { label: "Olho de boi",       name: "vehicle_attributes.exterior.has_bull_eye", loc: HAB_EXTERIOR },
    { label: "Sanita exterior",   name: "vehicle_attributes.exterior.has_external_wc", loc: HAB_EXTERIOR },
    { label: "Tampões",           name: "vehicle_attributes.exterior.has_hubcaps", loc: HAB_EXTERIOR },
    { label: "Escada exterior",   name: "vehicle_attributes.exterior.has_external_ladder", loc: HAB_EXTERIOR },
    { label: "Garagem",           name: "vehicle_attributes.exterior.garage.has_garage", loc: HAB_EXTERIOR },
    { label: "Garagem — abertura dos dois lados", name: "vehicle_attributes.exterior.garage.has_double_opening", loc: HAB_EXTERIOR },
    { label: "Garagem — espaçosa",name: "vehicle_attributes.exterior.garage.is_spacious", loc: HAB_EXTERIOR },
    { label: "Garagem — altura ajustável", name: "vehicle_attributes.exterior.garage.has_height_adjuster", loc: HAB_EXTERIOR },

    // ── HAB.6 Segurança e Fechaduras (accordionId="6") ───────────────────
    { label: "Alarme",            name: "vehicle_attributes.security.has_alarm", loc: HAB_SECURITY },
    { label: "Fecho alçapão",     name: "vehicle_attributes.security.has_hatch_lock", loc: HAB_SECURITY },
    { label: "Fecho cabine",      name: "vehicle_attributes.security.has_cabin_lock", loc: HAB_SECURITY },
    { label: "Porta de cofre",    name: "vehicle_attributes.security.has_safe_door", loc: HAB_SECURITY },
    { label: "Fecho gás",         name: "vehicle_attributes.security.has_gas_lock", loc: HAB_SECURITY },
    { label: "Fecho porta entrada", name: "vehicle_attributes.security.has_entry_door_lock", loc: HAB_SECURITY },
    { label: "Outras fechaduras (notas)", name: "vehicle_attributes.security.other_locks_notes", loc: HAB_SECURITY },

    // ── HAB.7 Chassis e Estrutura (accordionId="7") ──────────────────────
    { label: "Tipo de chassis",   name: "vehicle_attributes.chassis_structure.chassis_type", loc: HAB_CHASSIS },
    { label: "Notas de chassis",  name: "vehicle_attributes.chassis_structure.chassis_notes", loc: HAB_CHASSIS },
    { label: "Suspensão pneumática", name: "vehicle_attributes.chassis_structure.has_air_suspension", loc: HAB_CHASSIS },
    { label: "Compressor (suspensão pneumática)", name: "vehicle_attributes.chassis_structure.has_air_suspension_compressor", loc: HAB_CHASSIS },
    { label: "Rodado duplo",      name: "vehicle_attributes.chassis_structure.has_dual_rear_wheel", loc: HAB_CHASSIS },
    { label: "Macacos estabilizadores", name: "vehicle_attributes.exterior.has_stabilizers", loc: HAB_CHASSIS },
    { label: "Tapa-luz janelas",  name: "vehicle_attributes.chassis_structure.has_window_blackouts", loc: HAB_CHASSIS },
    { label: "Tapa-luz cabine",   name: "vehicle_attributes.chassis_structure.has_cabin_blackouts", loc: HAB_CHASSIS },
    { label: "Tipo tapa-luz cabine", name: "vehicle_attributes.chassis_structure.cabin_blackout_type", loc: HAB_CHASSIS },

    // ── HAB.8 Mobiliário Interior (accordionId="8") ──────────────────────
    { label: "Mesa rebatível",    name: "vehicle_attributes.interior_furniture.has_foldable_table", loc: HAB_INTERIOR },
    { label: "Bancos giratórios", name: "vehicle_attributes.interior_furniture.has_rotating_seats", loc: HAB_INTERIOR },
    { label: "Estado dos estofos",name: "vehicle_attributes.interior_furniture.upholstery_state", loc: HAB_INTERIOR },
    { label: "Cortinas",          name: "vehicle_attributes.interior_furniture.has_curtains", loc: HAB_INTERIOR },
    { label: "Guarda-fatos",      name: "vehicle_attributes.interior_furniture.has_wardrobe", loc: HAB_INTERIOR },
    { label: "Iluminação LED",    name: "vehicle_attributes.interior_furniture.has_led_lighting", loc: HAB_INTERIOR },
    { label: "Iluminação halo",   name: "vehicle_attributes.interior_furniture.has_halo_lighting", loc: HAB_INTERIOR },
    { label: "Suporte TV",        name: "vehicle_attributes.interior_furniture.has_tv_support", loc: HAB_INTERIOR },
    { label: "TV",                name: "vehicle_attributes.interior_furniture.has_tv", loc: HAB_INTERIOR },
    { label: "Painel de comandos",name: "vehicle_attributes.interior_furniture.has_command_panel", loc: HAB_INTERIOR },
    // Claraboias + remifront + mosquiteiras movidos para Interior no Lote 3 C
    // (chaves JSON permanecem em chassis_structure.* — desalinhamento documentado)
    { label: "Clarabóia turbovent", name: "vehicle_attributes.chassis_structure.has_turbovent_skylight", loc: HAB_INTERIOR },
    { label: "Clarabóia panorâmica", name: "vehicle_attributes.chassis_structure.has_panoramic_skylight", loc: HAB_INTERIOR },
    { label: "Clarabóia 40×40",   name: "vehicle_attributes.chassis_structure.has_40x40_skylight", loc: HAB_INTERIOR },
    { label: "Outras clarabóias", name: "vehicle_attributes.chassis_structure.other_skylights_notes", loc: HAB_INTERIOR },
    { label: "Remifront",         name: "vehicle_attributes.chassis_structure.has_remifront", loc: HAB_INTERIOR },
    { label: "Mosquiteiras janelas", name: "vehicle_attributes.chassis_structure.has_mosquito_nets", loc: HAB_INTERIOR },
    { label: "Porta mosquiteira", name: "vehicle_attributes.chassis_structure.has_door_mosquito_net", loc: HAB_INTERIOR },
    { label: "Infiltrações de água", name: "vehicle_attributes.interior_furniture.has_water_infiltrations", loc: HAB_INTERIOR },
    { label: "Notas sobre infiltrações", name: "vehicle_attributes.interior_furniture.infiltrations_notes", loc: HAB_INTERIOR },

    // ── HAB.9 Sala (accordionId="9") ─────────────────────────────────────
    { label: "Tipo de sala",      name: "vehicle_attributes.living_room.layout", loc: HAB_LIVING_ROOM },
    { label: "Mesa telescópica",  name: "vehicle_attributes.living_room.has_extending_table", loc: HAB_LIVING_ROOM },
];

// ═════════════════════════════════════════════════════════════════════════
// AUTO-DERIVADO — items dos 4 grupos de extras, lidos de EXTRA_GROUPS.
// NÃO duplica — se "Traction+" for adicionado a extraGroups.ts, aparece
// automaticamente na busca.
// ═════════════════════════════════════════════════════════════════════════

const buildExtrasEntries = (): FormSearchEntry[] => {
    const out: FormSearchEntry[] = [];
    for (const group of EXTRA_GROUPS) {
        const location = EXTRA_GROUP_LOCATION[group.key];
        if (!location) continue; // defensivo — se aparecer key nova sem mapeamento
        for (const item of group.items) {
            out.push({
                label: item,
                fieldName: null,
                location,
                normalizedLabel: normalizeForSearch(item),
            });
        }
    }
    return out;
};

// ═════════════════════════════════════════════════════════════════════════
// API pública: índice completo já normalizado, pronto para `.filter`.
// O FormSearchBar chama isto UMA vez (useMemo) e filtra em memória.
// ═════════════════════════════════════════════════════════════════════════

let CACHED_INDEX: FormSearchEntry[] | null = null;

export const getFormSearchIndex = (): FormSearchEntry[] => {
    if (CACHED_INDEX) return CACHED_INDEX;
    const manual: FormSearchEntry[] = RAW_MANUAL.map(({ label, name, loc, vehicleTypes }) => ({
        label,
        fieldName: name,
        location: loc,
        normalizedLabel: normalizeForSearch(label),
        // Override da entrada > herdado da location > undefined (todos).
        vehicleTypes: vehicleTypes ?? loc.vehicleTypes,
    }));
    CACHED_INDEX = [...manual, ...buildExtrasEntries()];
    return CACHED_INDEX;
};

/**
 * Filtra o índice pelo tipo de viatura corrente. Entradas sem `vehicleTypes`
 * (undefined) aplicam-se a todos — caso da maioria (~50 entradas universais
 * + 142 extras).
 */
export const filterIndexByVehicleType = (
    index: FormSearchEntry[],
    vehicleType: FormSearchVehicleType,
): FormSearchEntry[] => {
    return index.filter(
        (e) => !e.vehicleTypes || e.vehicleTypes.includes(vehicleType),
    );
};
