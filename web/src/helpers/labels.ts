// Tradução pt-PT de valores de domínio devolvidos crus pelo backend.
//
// Camada SEPARADA das mensagens de validação (que vivem em
// server/lang/pt/validation.php). Isto traduz VALORES (ex: "electric" →
// "Eléctrico"); o validation.php traduz MENSAGENS (ex: "O campo X é
// obrigatório."). Não misturar.
//
// `labelOf` faz graceful degradation: se o valor não estiver no mapa,
// devolve o valor cru. Assim nunca aparece undefined/null no UI.

import { BED_LABELS } from "pages/Cars/Car/data/vehicleAttributes";

// Lookup é case-insensitive: chaves dos maps são (por convenção) lowercase.
// "White", "WHITE", "white" → todas batem na key "white" do map.
// Se o valor já vem em pt-PT (ex: "Branco"), não bate em nenhuma key inglesa,
// e o fallback devolve o valor ORIGINAL (não o lowercased) — não estraga
// formatação.
export const labelOf = (
    value: string | null | undefined,
    map: Record<string, string>,
): string | null => {
    if (value == null || value === "") return null;
    return map[value.toLowerCase()] ?? value;
};

// Cobre EN, slugs Standvirtual ("gaz", "lpg", "plugin-hybrid", "hibride-gaz",
// "hibride-diesel") e variantes pt já em uso na BD (Carmine sync, imports).
// Lookup por slug normalizado lowercase.
export const FUEL_TYPE_LABELS: Record<string, string> = {
    "electric":        "Eléctrico",
    "petrol":          "Gasolina",
    "gaz":             "Gasolina",
    "gasoline":        "Gasolina",
    "diesel":          "Diesel",
    "hybrid":          "Híbrido",
    "hibride-gaz":     "Híbrido (gasolina)",
    "hibride-diesel":  "Híbrido (diesel)",
    "plug-in-hybrid":  "Híbrido plug-in",
    "plugin-hybrid":   "Híbrido plug-in",
    "plug_in_hybrid":  "Híbrido plug-in",
    "lpg":             "GPL",
    "gpl":             "GPL",
    "cng":             "GNC",
    "hydrogen":        "Hidrogénio",
    // Variantes pt já em uso na BD
    "gasolina":        "Gasolina",
    "hibrido":         "Híbrido",
    "eletrico":        "Eléctrico",
    "electrico":       "Eléctrico",
};

export const TRANSMISSION_LABELS: Record<string, string> = {
    "automatic":        "Automática",
    "manual":           "Manual",
    "semi_automatic":   "Semi-automática",
    "automatica":       "Automática",
    "caixa-automatica": "Automática",
    "caixa-manual":     "Manual",
};

// enum cars.condition: new | used | like_new | good | service | trade_in | classic
export const CONDITION_LABELS: Record<string, string> = {
    "new":       "Novo",
    "used":      "Usado",
    "like_new":  "Como novo",
    "good":      "Bom estado",
    "service":   "Serviço",
    "trade_in":  "Retoma",
    "classic":   "Clássico",
};

// enum cars.origin: national | imported
export const ORIGIN_LABELS: Record<string, string> = {
    "national": "Nacional",
    "imported": "Importado",
};

// Cores em uso na BD são esmagadoramente em EN (white/gray/black/...).
// labelOf faz lookup case-insensitive, logo "White"/"WHITE"/"white" batem todos.
// Valores já em pt-PT ("Branco") caem no fallback e mostram-se como vêm.
export const EXTERIOR_COLOR_LABELS: Record<string, string> = {
    // Brancos / pretos / cinzentos
    "white":          "Branco",
    "black":          "Preto",
    "gray":           "Cinzento",
    "grey":           "Cinzento",          // grafia britânica
    "dark-gray":      "Cinzento escuro",
    "dark-grey":      "Cinzento escuro",
    "light-gray":     "Cinzento claro",
    "light-grey":     "Cinzento claro",
    "silver":         "Prateado",
    "silver-gray":    "Cinzento prateado",
    "silver-grey":    "Cinzento prateado",
    "gray-antracite": "Cinzento antracite",
    "grey-antracite": "Cinzento antracite",
    "antracite":      "Antracite",
    "anthracite":     "Antracite",
    // Azuis
    "blue":           "Azul",
    "dark-blue":      "Azul escuro",
    "light-blue":     "Azul claro",
    "navy":           "Azul-marinho",
    // Vermelhos / amarelos / laranjas
    "red":            "Vermelho",
    "dark-red":       "Vermelho escuro",
    "yellow":         "Amarelo",
    "orange":         "Laranja",
    // Verdes
    "green":          "Verde",
    "dark-green":     "Verde escuro",
    "light-green":    "Verde claro",
    // Castanhos / outros
    "brown":          "Castanho",
    "beige":          "Bege",
    "gold":           "Dourado",
    "bronze":         "Bronze",
    "purple":         "Roxo",
    "pink":           "Rosa",
};

// Re-export do BED_LABELS canónico (fonte: pages/Cars/Car/data/vehicleAttributes.ts).
// Não duplicar — qualquer Ficha/PublicResource que queira labels de cama importa daqui.
export { BED_LABELS };
