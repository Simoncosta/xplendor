import { Alert, Collapse, Spinner, Table } from "reactstrap";
import type {
    MarketAggregate,
    MarketAggregateConfidence,
    MarketComparable,
} from "../../../../../types/api";
import { labelOf, MARKET_SOURCE_LABELS } from "../../../../../helpers/labels";

// ─────────────────────────────────────────────────────────────────────────────
// FASE 1b — vista do motor de similaridade de AUTOCARAVANAS.
// (Polish Fase B) — apresentação migrada para componentes Velzon (Alert, Badge,
// Table, Collapse) e tokens; sem cores hardcoded. A LÓGICA e os ESTADOS da
// Fase 1b mantêm-se intactos.
//
// Difere da vista dos carros porque o preço é por TIPOLOGIA + ano (cross-marca),
// não por marca+modelo. O selo comunica essa natureza. Essencial em destaque:
// preço (mediana), grau de confiança e nº de anúncios; detalhe (banda p25-p75,
// top 5) recolhido.
//
// Contrato de dados (backend Fase 1, provado):
//   · method='motorhome_similarity_v1' seleciona esta vista.
//   · n=0 (none/failed) → SEM preço; funil + link StandVirtual.
//   · n=1-2 (low)       → preço indicativo + aviso "poucos anúncios".
//   · p25/p75 só com n>=4.
// ─────────────────────────────────────────────────────────────────────────────

// Confiança → cor de tema Velzon (sem hex). O soft badge usa bg-{cor}-subtle +
// text-{cor}, que o Velzon trata nos dois temas.
const CONFIDENCE_UI: Record<MarketAggregateConfidence, { label: string; color: string }> = {
    high:   { label: "Confiança alta",   color: "success" },
    medium: { label: "Confiança média",  color: "warning" },
    low:    { label: "Confiança baixa",  color: "danger" },
    none:   { label: "Sem dados",        color: "secondary" },
};

const LAYOUT_LABELS: Record<string, string> = {
    perfiladas: "Perfilada",
    integral:   "Integral",
    capucine:   "Capucine",
    furgao:     "Furgão",
    caravana:   "Caravana",
};

function layoutLabel(slug: string | null | undefined): string {
    if (!slug) return "autocaravana";
    return LAYOUT_LABELS[slug] ?? slug;
}

function formatCurrency(value: number | null | undefined): string {
    if (value === null || value === undefined) return "—";
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);
}

function formatDate(iso: string | null | undefined): string | null {
    if (!iso) return null;
    const d = new Date(iso);
    if (isNaN(d.getTime())) return null;
    return new Intl.DateTimeFormat("pt-PT", { day: "2-digit", month: "short" }).format(d);
}

// ── Selo "comparação por tipologia + ano" ────────────────────────────────────
function TypologySeal() {
    return (
        <span
            className="badge bg-info-subtle text-info fw-normal fs-11 px-2 py-1"
            style={{ cursor: "help" }}
            title={
                "O preço das autocaravanas é calculado por TIPOLOGIA e ano " +
                "(ex.: perfiladas de anos próximos, de qualquer marca), não por " +
                "marca+modelo como nos automóveis. O mercado de autocaravanas é " +
                "fragmentado, por isso comparar por tipologia dá uma amostra útil."
            }
        >
            <i className="ri-price-tag-3-line me-1" />
            Comparação por tipologia + ano ⓘ
        </span>
    );
}

// ── Estado vazio (n=0): funil + link StandVirtual, nunca um vazio sem explicação
export function MotorhomeEmptyState({
    aggregate,
    onRefresh,
    refreshing,
}: {
    aggregate: MarketAggregate;
    onRefresh: () => void;
    refreshing: boolean;
}) {
    const funnel = aggregate.funnel ?? null;
    const tipologia = layoutLabel(funnel?.layout ?? null);

    let headline: string;
    let detail: string | null = null;

    if (aggregate.status === "failed" || !funnel) {
        // 'failed' = faltam DADOS de entrada (tipologia/ano). Não afirmamos que a
        // categoria está em falta (pode estar lá e faltar o ano).
        headline = "Faltam dados para calcular o preço por tipologia.";
        detail = "Confirma na Ficha que a categoria (tipologia) e o ano de registo estão preenchidos.";
    } else if (funnel.layout_total > 0) {
        headline = `Vimos ${funnel.layout_total} ${
            funnel.layout_total === 1 ? "autocaravana" : "autocaravanas"
        } desta tipologia (${tipologia}), mas 0 na janela de comparação (${funnel.year_from}–${funnel.year_to}).`;
        detail = "Sem anúncios próximos em ano e recentes, não mostramos um preço para não induzir em erro.";
    } else {
        headline = `Ainda não vimos autocaravanas desta tipologia (${tipologia}) no mercado.`;
        detail = "Assim que surgirem anúncios comparáveis, o preço fica disponível aqui.";
    }

    return (
        <div>
            <Alert color="light" className="mb-3">
                <div className="d-flex align-items-start gap-2">
                    <i className="ri-search-eye-line fs-18 text-muted mt-1" />
                    <div>
                        <p className="fs-13 text-body mb-1 fw-medium">{headline}</p>
                        {detail && <p className="text-muted fs-12 mb-0">{detail}</p>}
                    </div>
                </div>
            </Alert>

            <div className="d-flex align-items-center gap-2 flex-wrap">
                {aggregate.search_url && (
                    <a
                        href={aggregate.search_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="btn btn-sm btn-soft-primary"
                    >
                        <i className="ri-external-link-line align-bottom me-1" />
                        Ver no StandVirtual
                    </a>
                )}
                <button className="btn btn-sm btn-soft-secondary" onClick={onRefresh} disabled={refreshing}>
                    {refreshing ? <><Spinner size="sm" className="me-1" />A tentar</> : "Tentar novamente"}
                </button>
            </div>
        </div>
    );
}

// ── Estado de ERRO TÉCNICO (status='error') ──────────────────────────────────
// Distinto do "faltam dados": o cálculo REBENTOU. NUNCA culpar a categoria.
export function MotorhomeErrorState({
    onRefresh,
    refreshing,
    userRole,
}: {
    onRefresh: () => void;
    refreshing: boolean;
    userRole?: string;
}) {
    return (
        <div>
            <Alert color="danger" className="mb-3">
                <p className="fs-13 mb-1 fw-medium">
                    <i className="ri-error-warning-line align-bottom me-1" />
                    Ocorreu um erro técnico ao calcular o preço de mercado.
                </p>
                <p className="mb-0 fs-12">
                    Não é um problema com os dados desta autocaravana. Tenta novamente dentro de momentos.
                </p>
                {userRole === "root" && (
                    <p className="fs-12 mb-0 mt-1">
                        [root] Estado: error — verificar logs do worker (reiniciar após deploy de código novo).
                    </p>
                )}
            </Alert>
            <button className="btn btn-sm btn-soft-secondary" onClick={onRefresh} disabled={refreshing}>
                {refreshing ? <><Spinner size="sm" className="me-1" />A tentar</> : "Tentar novamente"}
            </button>
        </div>
    );
}

// ── Estado com dados (success, n>=1) ─────────────────────────────────────────
export function MotorhomeSuccessBody({
    aggregate,
    showComparables,
    onToggleComparables,
}: {
    aggregate: MarketAggregate;
    showComparables: boolean;
    onToggleComparables: () => void;
}) {
    const conf = CONFIDENCE_UI[aggregate.confidence] ?? CONFIDENCE_UI.none;
    const n = aggregate.comparables_count;
    const isIndicative = aggregate.confidence === "low";

    const p25 = aggregate.prices.p25 ?? null;
    const p75 = aggregate.prices.p75 ?? null;
    const hasBand = p25 !== null && p75 !== null;

    return (
        <div>
            {isIndicative && (
                <Alert color="warning" className="fs-13 d-flex align-items-start gap-2">
                    <i className="ri-error-warning-line fs-16 mt-1" />
                    <span>
                        Valor indicativo — apenas {n} {n === 1 ? "anúncio comparável" : "anúncios comparáveis"} nesta
                        janela. Interpretar com precaução.
                    </span>
                </Alert>
            )}

            {/* Essencial em destaque: preço (mediana) + confiança */}
            <div className="row g-3 mb-3">
                <div className="col-sm-7">
                    <div className="border rounded p-3 h-100" style={{ background: "var(--vz-tertiary-bg)" }}>
                        <span className="text-muted fs-12 d-block mb-1">
                            {isIndicative ? "Preço indicativo (mediana)" : "Preço de mercado (mediana)"}
                        </span>
                        <div className="fw-bold fs-24 text-body lh-1">
                            {formatCurrency(aggregate.prices.median)}
                        </div>
                        {hasBand && (
                            <span className="text-muted fs-12 d-block mt-2">
                                A maioria entre {formatCurrency(p25)} e {formatCurrency(p75)}
                            </span>
                        )}
                    </div>
                </div>
                <div className="col-sm-5">
                    <div className="border rounded p-3 h-100" style={{ background: "var(--vz-tertiary-bg)" }}>
                        <span className="text-muted fs-12 d-block mb-2">Fiabilidade</span>
                        <span className={`badge bg-${conf.color}-subtle text-${conf.color} fs-13 px-2 py-1`}>
                            <i className="ri-shield-check-line align-bottom me-1" />
                            {conf.label}
                        </span>
                        <span className="text-muted fs-12 d-block mt-2">
                            {n} {n === 1 ? "anúncio" : "anúncios"} usados
                        </span>
                    </div>
                </div>
            </div>

            {/* Selo tipologia + toggle detalhe */}
            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap pt-2 border-top">
                <TypologySeal />
                <button className="btn btn-soft-secondary btn-sm" onClick={onToggleComparables}>
                    <i className={`align-bottom me-1 ${showComparables ? "ri-arrow-up-s-line" : "ri-arrow-down-s-line"}`} />
                    {showComparables ? "Ocultar detalhe" : "Ver detalhe"}
                </button>
            </div>

            <Collapse isOpen={showComparables}>
                <MotorhomeDetail aggregate={aggregate} hasBand={hasBand} p25={p25} p75={p75} />
            </Collapse>
        </div>
    );
}

function MotorhomeDetail({
    aggregate,
    hasBand,
    p25,
    p75,
}: {
    aggregate: MarketAggregate;
    hasBand: boolean;
    p25: number | null;
    p75: number | null;
}) {
    const outliers = aggregate.outliers_removed ?? 0;

    return (
        <div className="mt-3 pt-3 border-top">
            {hasBand ? (
                <div className="mb-3">
                    <span className="text-muted fs-12 d-block mb-1">Banda central de preços</span>
                    <div className="fw-semibold fs-14 text-body">
                        {formatCurrency(p25)} — {formatCurrency(p75)}
                    </div>
                    <span className="text-muted fs-11">
                        Metade dos anúncios cai dentro desta banda (percentis 25–75).
                        {outliers > 0 && ` ${outliers} ${outliers === 1 ? "anúncio excluído" : "anúncios excluídos"} por preço atípico.`}
                    </span>
                </div>
            ) : (
                outliers > 0 && (
                    <p className="text-muted fs-11 mb-3">
                        {outliers} {outliers === 1 ? "anúncio excluído" : "anúncios excluídos"} por preço atípico.
                    </p>
                )
            )}

            <span className="text-muted fs-12 d-block mb-2">
                Autocaravanas mais parecidas ({aggregate.top_comparables.length})
            </span>
            <MotorhomeComparablesList comparables={aggregate.top_comparables} searchUrl={aggregate.search_url} />
        </div>
    );
}

function MotorhomeComparablesList({
    comparables,
    searchUrl,
}: {
    comparables: MarketComparable[];
    searchUrl: string | null;
}) {
    if (comparables.length === 0) {
        return <p className="text-muted fs-13 mb-0">Sem comparáveis para apresentar.</p>;
    }

    return (
        <div className="table-responsive">
            <Table className="table-sm align-middle mb-0">
                <thead className="text-muted">
                    <tr>
                        <th scope="col">Autocaravana</th>
                        <th scope="col" className="text-end">Preço</th>
                        <th scope="col" className="text-end">Semelhança</th>
                        <th scope="col" className="text-end">Visto</th>
                    </tr>
                </thead>
                <tbody>
                    {comparables.map((item, i) => {
                        const sim = item.similarity_score;
                        const simPct = sim !== null && sim !== undefined ? Math.round(sim * 100) : null;
                        const scrapedAt = formatDate(item.scraped_at);

                        const chips = [
                            item.year ? String(item.year) : null,
                            item.beds != null ? `${item.beds} ${item.beds === 1 ? "cama" : "camas"}` : null,
                            item.displacement != null ? `${item.displacement} cc` : null,
                            item.length != null ? `${item.length.toLocaleString("pt-PT")} m` : null,
                            item.region ?? null,
                            labelOf(item.source, MARKET_SOURCE_LABELS),
                        ].filter(Boolean).join(" · ");

                        const hasUrl = !!item.url;
                        const href = hasUrl ? item.url : (searchUrl ?? "https://www.standvirtual.com/autocaravanas");

                        return (
                            <tr key={i}>
                                <td>
                                    <div className="d-flex align-items-center gap-2">
                                        <span className="fw-semibold text-body text-truncate" style={{ maxWidth: 240 }} title={item.title}>
                                            {item.title}
                                        </span>
                                        <a
                                            href={href}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-muted flex-shrink-0"
                                            aria-label={hasUrl ? "Ver anúncio" : "Pesquisar semelhantes no StandVirtual"}
                                        >
                                            <i className="ri-external-link-line" />
                                        </a>
                                    </div>
                                    {chips && <span className="text-muted fs-12">{chips}</span>}
                                </td>
                                <td className="text-end fw-semibold">{formatCurrency(item.price)}</td>
                                <td className="text-end">
                                    {simPct !== null ? (
                                        <span
                                            className="badge bg-light text-muted"
                                            title="Grau de semelhança com a tua autocaravana (ano, cilindrada, dormidas)"
                                        >
                                            {simPct}%
                                        </span>
                                    ) : (
                                        <span className="text-muted">—</span>
                                    )}
                                </td>
                                <td className="text-end text-muted fs-12">{scrapedAt ?? "—"}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </Table>
        </div>
    );
}
