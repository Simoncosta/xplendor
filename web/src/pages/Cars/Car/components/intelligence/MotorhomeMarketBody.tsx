import { Spinner } from "reactstrap";
import type {
    MarketAggregate,
    MarketAggregateConfidence,
    MarketComparable,
} from "../../../../../types/api";
import { labelOf, MARKET_SOURCE_LABELS } from "../../../../../helpers/labels";

// ─────────────────────────────────────────────────────────────────────────────
// FASE 1b — vista do motor de similaridade de AUTOCARAVANAS.
//
// Difere da vista dos carros (MarketPositionCard) porque o preço é calculado
// por TIPOLOGIA + ano (cross-marca), não por marca+modelo. O selo comunica essa
// natureza ao utilizador. O essencial em destaque: preço (mediana), grau de
// confiança bem visível (é o que torna o motor honesto) e nº de anúncios. O
// detalhe (banda p25-p75, top 5 comparáveis) fica recolhido.
//
// Contrato de dados assumido (backend Fase 1, provado):
//   · method='motorhome_similarity_v1' seleciona esta vista.
//   · n=0 (none/failed) → SEM preço; funil + link StandVirtual.
//   · n=1-2 (low)       → preço indicativo + aviso "poucos anúncios".
//   · p25/p75 só com n>=4.
// ─────────────────────────────────────────────────────────────────────────────

const CONFIDENCE_UI: Record<
    MarketAggregateConfidence,
    { label: string; dot: string; text: string; bg: string; border: string }
> = {
    high:   { label: "Confiança alta",   dot: "#0ab39c", text: "text-success", bg: "bg-success-subtle", border: "#0ab39c33" },
    medium: { label: "Confiança média",  dot: "#f7b84b", text: "text-warning", bg: "bg-warning-subtle", border: "#f7b84b33" },
    low:    { label: "Confiança baixa",  dot: "#f06548", text: "text-danger",  bg: "bg-danger-subtle",  border: "#f0654833" },
    none:   { label: "Sem dados",        dot: "#878a99", text: "text-muted",   bg: "bg-light",          border: "#878a9933" },
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
// Comunica a diferença face aos carros: aqui o preço é cross-marca, agrupado
// por tipologia. Importante para o utilizador entender a natureza do valor.
function TypologySeal({ layout }: { layout: string | null | undefined }) {
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
    const layout = funnel?.layout ?? null;
    const tipologia = layoutLabel(layout);

    // Copy consoante o que o funil revela:
    //   · sem funil (ex.: falhou por falta de tipologia) → mensagem accionável.
    //   · vimos viaturas da tipologia mas nenhuma na janela → funil explícito.
    //   · nunca vimos nenhuma da tipologia → honestidade direta.
    let headline: string;
    let detail: string | null = null;

    if (aggregate.status === "failed" || !funnel) {
        // 'failed' = faltam DADOS de entrada do motor por tipologia. NÃO
        // afirmamos que a categoria está em falta (pode estar lá e faltar o
        // ano) — pedimos para confirmar ambos, sem culpar o que já existe.
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
        <div className="py-1">
            <div className="d-flex align-items-start gap-2 mb-2">
                <i className="ri-search-eye-line fs-18 text-muted mt-1" />
                <div>
                    <p className="fs-13 text-body mb-1">{headline}</p>
                    {detail && <p className="text-muted fs-12 mb-0">{detail}</p>}
                </div>
            </div>

            <div className="d-flex align-items-center gap-2 flex-wrap mt-3">
                {aggregate.search_url && (
                    <a
                        href={aggregate.search_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="btn btn-sm btn-outline-primary"
                    >
                        <i className="ri-external-link-line me-1" />
                        Ver no StandVirtual
                    </a>
                )}
                <button
                    className="btn btn-sm btn-outline-secondary"
                    onClick={onRefresh}
                    disabled={refreshing}
                >
                    {refreshing ? <><Spinner size="sm" className="me-1" />A tentar</> : "Tentar novamente"}
                </button>
            </div>
        </div>
    );
}

// ── Estado de ERRO TÉCNICO (status='error') ──────────────────────────────────
// Distinto do "faltam dados": aqui o cálculo REBENTOU (bug, worker desatualizado,
// timeout, etc.). A categoria pode estar perfeitamente preenchida, por isso NUNCA
// mandamos o utilizador confirmá-la. Mensagem honesta + retry.
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
        <div className="py-1">
            <div className="d-flex align-items-start gap-2 mb-2">
                <i className="ri-error-warning-line fs-18 text-danger mt-1" />
                <div>
                    <p className="fs-13 text-body mb-1">
                        Ocorreu um erro técnico ao calcular o preço de mercado.
                    </p>
                    <p className="text-muted fs-12 mb-0">
                        Não é um problema com os dados desta autocaravana. Tenta novamente dentro de momentos.
                    </p>
                    {userRole === "root" && (
                        <p className="text-danger fs-12 mb-0 mt-1">
                            [root] Estado: error — verificar logs do worker (reiniciar após deploy de código novo).
                        </p>
                    )}
                </div>
            </div>
            <button
                className="btn btn-sm btn-outline-secondary mt-2"
                onClick={onRefresh}
                disabled={refreshing}
            >
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
    const isIndicative = aggregate.confidence === "low"; // n=1-2 (ou dispersão alta)
    const layout = aggregate.funnel?.layout ?? null;

    const p25 = aggregate.prices.p25 ?? null;
    const p75 = aggregate.prices.p75 ?? null;
    const hasBand = p25 !== null && p75 !== null;

    return (
        <div>
            {isIndicative && (
                <div
                    className="mb-3 px-3 py-2 rounded-3 fs-13 bg-warning-subtle text-warning d-flex align-items-start gap-2"
                    style={{ border: "1px solid #f7b84b33" }}
                >
                    <i className="ri-error-warning-line mt-1" />
                    <span>
                        Valor indicativo — apenas {n} {n === 1 ? "anúncio comparável" : "anúncios comparáveis"} nesta
                        janela. Interpretar com precaução.
                    </span>
                </div>
            )}

            {/* Essencial em destaque: preço (mediana) + confiança */}
            <div className="row g-3 mb-3">
                <div className="col-sm-7">
                    <div
                        style={{
                            padding: "14px 16px",
                            borderRadius: "12px",
                            border: "1px solid var(--vz-border-color)",
                            background: "var(--vz-tertiary-bg)",
                        }}
                    >
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
                    <div
                        className={conf.bg}
                        style={{
                            padding: "14px 16px",
                            borderRadius: "12px",
                            border: `1px solid ${conf.border}`,
                            height: "100%",
                        }}
                    >
                        <span className="text-muted fs-12 d-block mb-1">Fiabilidade</span>
                        <div className={`d-flex align-items-center gap-2 fw-semibold fs-15 ${conf.text}`}>
                            <span
                                style={{
                                    width: 10,
                                    height: 10,
                                    borderRadius: "50%",
                                    background: conf.dot,
                                    display: "inline-block",
                                    flexShrink: 0,
                                }}
                            />
                            {conf.label}
                        </div>
                        <span className="text-muted fs-12 d-block mt-1">
                            {n} {n === 1 ? "anúncio" : "anúncios"} usados
                        </span>
                    </div>
                </div>
            </div>

            {/* Selo tipologia + toggle detalhe */}
            <div
                className="d-flex align-items-center justify-content-between gap-2 flex-wrap pt-2"
                style={{ borderTop: "1px solid var(--vz-border-color)" }}
            >
                <TypologySeal layout={layout} />
                <button
                    className="btn btn-link btn-sm p-0 text-decoration-none fs-12"
                    onClick={onToggleComparables}
                >
                    {showComparables ? "Ocultar detalhe ↑" : "Ver detalhe ↓"}
                </button>
            </div>

            {showComparables && (
                <MotorhomeDetail aggregate={aggregate} hasBand={hasBand} p25={p25} p75={p75} />
            )}
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
        <div className="mt-3" style={{ borderTop: "1px solid var(--vz-border-color)", paddingTop: 12 }}>
            {/* Banda de preços (só n>=4) */}
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
        return (
            <p className="text-muted fs-13 mb-0">
                Sem comparáveis para apresentar.
            </p>
        );
    }

    return (
        <div className="d-flex flex-column gap-2">
            {comparables.map((item, i) => {
                const sim = item.similarity_score;
                const simPct = sim !== null && sim !== undefined ? Math.round(sim * 100) : null;
                const scrapedAt = formatDate(item.scraped_at);

                // Cilindrada / comprimento / dormidas visíveis para o humano
                // comparar (o comprimento não é critério do motor, mas ajuda).
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
                    <div
                        key={i}
                        className="d-flex align-items-start justify-content-between gap-3 rounded-3 px-3 py-2"
                        style={{ background: "var(--vz-tertiary-bg)", border: "1px solid var(--vz-border-color)" }}
                    >
                        <div style={{ minWidth: 0, flex: 1 }}>
                            <div className="d-flex align-items-center gap-2">
                                <span
                                    className="fw-semibold fs-13 text-body"
                                    style={{
                                        overflow: "hidden",
                                        textOverflow: "ellipsis",
                                        whiteSpace: "nowrap",
                                        maxWidth: "100%",
                                    }}
                                    title={item.title}
                                >
                                    {item.title}
                                </span>
                                <a
                                    href={href}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-muted fs-11 text-decoration-none flex-shrink-0"
                                    style={{ lineHeight: 1, cursor: "pointer" }}
                                    aria-label={hasUrl ? "Ver anúncio" : "Pesquisar semelhantes no StandVirtual"}
                                >
                                    ↗
                                </a>
                            </div>
                            {chips && <span className="text-muted fs-12">{chips}</span>}
                        </div>

                        <div className="text-end flex-shrink-0">
                            <div className="fw-semibold fs-14">{formatCurrency(item.price)}</div>
                            <div className="d-flex align-items-center justify-content-end gap-2 mt-1">
                                {simPct !== null && (
                                    <span
                                        className="badge bg-light text-muted fs-11"
                                        title="Grau de semelhança com a tua autocaravana (ano, cilindrada, dormidas)"
                                    >
                                        {simPct}% semelhante
                                    </span>
                                )}
                                {scrapedAt && <span className="text-muted fs-11">{scrapedAt}</span>}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
