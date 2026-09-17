// XPLENDOR — CRM / funil de leads. As fases do funil e os motivos de perda.
// Espelha os valores validados no backend (CarLead::STATUSES / LOSS_REASONS).

export type LeadStatus =
    | "new" | "contacted" | "visit" | "qualified" | "negotiation" | "won" | "lost" | "spam";

export interface LeadCar {
    id: number;
    version?: string;
    brand?: { name?: string } | null;
    model?: { name?: string } | null;
    images?: { image: string; is_primary: boolean }[];
}

export interface ILead {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    message?: string | null;
    notes?: string | null;
    status: LeadStatus;
    lost_reason?: string | null;
    created_at: string;
    channel?: string | null;
    utm_source?: string | null;
    utm_campaign?: string | null;
    car?: LeadCar | null;
    car_id?: number;
}

// Colunas do funil (ordem = fluxo). `spam` NÃO entra no funil (marca-se na lista).
// `qualified` é apresentado como "Proposta" (fase intermédia já existente).
export const LEAD_STAGES: { key: LeadStatus; label: string; color: string }[] = [
    { key: "new",         label: "Nova",       color: "secondary" },
    { key: "contacted",   label: "Contactada", color: "info" },
    { key: "visit",       label: "Visita",     color: "primary" },
    { key: "qualified",   label: "Proposta",   color: "warning" },
    { key: "negotiation", label: "Negociação", color: "warning" },
    { key: "won",         label: "Venda",      color: "success" },
    { key: "lost",        label: "Perdida",    color: "danger" },
];

export const LEAD_STATUS_META: Record<LeadStatus, { label: string; color: string }> = {
    new:         { label: "Nova",       color: "secondary" },
    contacted:   { label: "Contactada", color: "info" },
    visit:       { label: "Visita",     color: "primary" },
    qualified:   { label: "Proposta",   color: "warning" },
    negotiation: { label: "Negociação", color: "warning" },
    won:         { label: "Venda",      color: "success" },
    lost:        { label: "Perdida",    color: "danger" },
    spam:        { label: "Spam",       color: "dark" },
};

// Motivos de perda (11) — chave gravada em lost_reason, rótulo pt-PT.
export const LOSS_REASONS: { key: string; label: string }[] = [
    { key: "preco",              label: "Preço" },
    { key: "financiamento",      label: "Financiamento recusado" },
    { key: "veiculo_inadequado", label: "Veículo inadequado" },
    { key: "concorrencia",       label: "Comprou na concorrência" },
    { key: "adiou",              label: "Adiou a compra" },
    { key: "sem_resposta",       label: "Deixou de responder" },
    { key: "so_pesquisa",        label: "Só estava a pesquisar" },
    { key: "distancia",          label: "Distância / localização" },
    { key: "ja_comprou",         label: "Já comprou outro" },
    { key: "retoma",             label: "Retoma insuficiente" },
    { key: "outro",              label: "Outro" },
];

export const lossReasonLabel = (key?: string | null): string =>
    LOSS_REASONS.find((r) => r.key === key)?.label ?? (key ?? "—");
