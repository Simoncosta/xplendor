import { useEffect, useRef, useState } from "react";
import { Alert, Card, CardBody, CardHeader, Collapse, Progress, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import type {
    MarketAggregate,
    MarketAggregateStatus,
    MarketPriceSignal,
} from "../../../../../types/api";
import { fetchMarketAggregate, refreshMarketAggregate } from "../../../../../helpers/marketAggregate_helper";
import { labelOf, MARKET_SOURCE_LABELS } from "../../../../../helpers/labels";
import { extractApiError } from "../../../../../helpers/error_helper";
import ComparablesList from "./ComparablesList";
import { MotorhomeSuccessBody, MotorhomeEmptyState, MotorhomeErrorState } from "./MotorhomeMarketBody";

// FASE 1b — o motor de autocaravanas marca os seus aggregates com este method.
// A vista dos carros é escolhida quando method é null/ausente (cascata clássica).
const MOTORHOME_METHOD = "motorhome_similarity_v1";

const POLL_INTERVAL_MS  = 10_000;
const MAX_POLL_ATTEMPTS = 18; // 3 minutes
const STORAGE_TTL_MS    = 20 * 60 * 1000; // 20 minutes

const TERMINAL_STATUSES: MarketAggregateStatus[] = ["success", "none", "blocked", "error", "failed"];

const SIGNAL_CONFIG: Record<
    MarketPriceSignal,
    { label: string; badgeClass: string; iconClass: string }
> = {
    overpriced:    { label: "Acima do mercado",           badgeClass: "bg-danger-subtle text-danger",   iconClass: "ri-alert-line" },
    slightly_high: { label: "Ligeiramente acima",         badgeClass: "bg-warning-subtle text-warning", iconClass: "ri-error-warning-line" },
    fair:          { label: "Alinhado com o mercado",     badgeClass: "bg-light text-muted",            iconClass: "ri-checkbox-circle-line" },
    competitive:   { label: "Competitivo",                badgeClass: "bg-success-subtle text-success", iconClass: "ri-thumb-up-line" },
};

const CONFIDENCE_LABEL: Record<string, string> = {
    high:   "Alta",
    medium: "Média",
    low:    "Baixa",
    none:   "Sem dados",
};

interface Props {
    companyId: number;
    carId: number;
    userRole?: string;
}

export default function MarketPositionCard({ companyId, carId, userRole }: Props) {
    const [aggregate, setAggregate]             = useState<MarketAggregate | null | undefined>(undefined);
    const [loadingInitial, setLoadingInitial]   = useState(true);
    const [refreshing, setRefreshing]           = useState(false);
    const [showComparables, setShowComparables] = useState(false);
    const [pollAttempts, setPollAttempts]       = useState(0);
    const [pollTimedOut, setPollTimedOut]       = useState(false);
    const [networkError, setNetworkError]       = useState(false);

    const pollRef    = useRef<ReturnType<typeof setInterval> | null>(null);
    const mountedRef = useRef(true);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
        };
    }, []);

    const stopPolling = () => {
        if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }
    };

    const startPolling = (aggregateId?: number) => {
        stopPolling();
        setPollAttempts(0);

        pollRef.current = setInterval(async () => {
            setPollAttempts((prev) => {
                const next = prev + 1;
                if (next >= MAX_POLL_ATTEMPTS) {
                    stopPolling();
                    setRefreshing(false);
                    setPollTimedOut(true);
                }
                return next;
            });

            try {
                const data = await fetchMarketAggregate(companyId, carId, aggregateId);
                if (data && TERMINAL_STATUSES.includes(data.status)) {
                    clearStoredAggregateId(carId);
                    setAggregate(data);
                    stopPolling();
                    setRefreshing(false);
                }
            } catch {
                // silent — keep polling until max attempts
            }
        }, POLL_INTERVAL_MS);
    };

    useEffect(() => {
        let cancelled = false;
        const storedId = readStoredAggregateId(carId);

        fetchMarketAggregate(companyId, carId, storedId ?? undefined)
            .then((data) => {
                if (cancelled) return;
                setNetworkError(false);
                setAggregate(data);

                if (data) {
                    if (TERMINAL_STATUSES.includes(data.status)) {
                        clearStoredAggregateId(carId);
                    } else {
                        startPolling(storedId ?? undefined);
                    }
                }
            })
            .catch(() => {
                if (cancelled) return;
                setNetworkError(true);
            })
            .finally(() => {
                if (!cancelled) setLoadingInitial(false);
            });

        return () => {
            cancelled = true;
            stopPolling();
        };
    }, [companyId, carId]);

    const handleRefresh = async () => {
        if (refreshing) return;
        setPollTimedOut(false);
        setRefreshing(true);

        try {
            const result = await refreshMarketAggregate(companyId, carId);
            // Write to sessionStorage BEFORE mountedRef guard — ensures the id is
            // persisted even if the user navigated away during the POST round-trip.
            writeStoredAggregateId(carId, result.aggregate_id);
            if (!mountedRef.current) return;
            toast.success("Análise de mercado iniciada. Os resultados aparecem em breve.");
            startPolling(result.aggregate_id);
        } catch (err: unknown) {
            if (!mountedRef.current) return;
            setRefreshing(false);
            // MS2.g item 1 — extractApiError consolida status+message do shape
            // que o interceptor preserva em 4xx. Antes, o catch lia
            // `err.response.status` mas o interceptor já tinha desempacotado o
            // body → status sempre undefined → todos os 4xx caíam no else.
            // Mensagem 422/429 vem do backend em pt-PT (sem hardcoded).
            const { status, message } = extractApiError(err);
            if (status === 429) {
                toast.warning(message ?? "Aguarda alguns minutos antes de actualizar novamente.");
            } else if (status === 422) {
                toast.info(message ?? "Análise de mercado não disponível para esta viatura.");
            } else {
                toast.error("Não foi possível iniciar a análise de mercado.");
            }
        }
    };

    const retryFetch = async () => {
        setLoadingInitial(true);
        setNetworkError(false);

        try {
            const storedId = readStoredAggregateId(carId);
            const data = await fetchMarketAggregate(companyId, carId, storedId ?? undefined);
            setNetworkError(false);
            setAggregate(data);
            if (data) {
                if (TERMINAL_STATUSES.includes(data.status)) {
                    clearStoredAggregateId(carId);
                } else {
                    startPolling(storedId ?? undefined);
                }
            }
        } catch {
            setNetworkError(true);
        } finally {
            setLoadingInitial(false);
        }
    };

    return (
        <Card className="mb-0">
            <CardHeader className="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <h6 className="card-title mb-0 fw-semibold">
                    <i className="ri-line-chart-line align-bottom me-1 text-primary" />
                    Posição no mercado
                </h6>
                {(pollTimedOut || (aggregate && TERMINAL_STATUSES.includes(aggregate.status))) && (() => {
                    // MS2.g item 2 — disabled quando preço efectivo é null/≤0.
                    // Sem preço interno, o degrau 5 da cascata fica matematicamente
                    // inútil (guard backend MS1.c) e os outros degraus dão poucos
                    // sinais. Evita scrapes redundantes + tooltip orienta o user.
                    // hide_price_online não influencia — preço INTERNO é o que conta.
                    const carPrice = aggregate?.comparison?.car_price ?? null;
                    const noPrice  = carPrice === null || carPrice <= 0;
                    const disabledReason = noPrice
                        ? "Define um preço interno para esta viatura para activar a análise de mercado."
                        : undefined;
                    return (
                        <button
                            className="btn btn-sm btn-soft-primary"
                            onClick={handleRefresh}
                            disabled={refreshing || noPrice}
                            title={disabledReason}
                        >
                            {refreshing
                                ? <><Spinner size="sm" className="me-1" />A actualizar</>
                                : <><i className="ri-refresh-line align-bottom me-1" />Actualizar agora</>}
                        </button>
                    );
                })()}
            </CardHeader>

            <CardBody>
                {loadingInitial
                    ? <LoadingState />
                    : networkError
                        ? <NetworkErrorState onRetry={retryFetch} />
                        : <Body
                            aggregate={aggregate}
                            refreshing={refreshing}
                            showComparables={showComparables}
                            onToggleComparables={() => setShowComparables((v) => !v)}
                            onRefresh={handleRefresh}
                            pollAttempts={pollAttempts}
                            pollTimedOut={pollTimedOut}
                            userRole={userRole}
                            companyId={companyId}
                            carId={carId}
                        />
                }
            </CardBody>
        </Card>
    );
}

// ─── sub-components ───────────────────────────────────────────────────────────

function LoadingState() {
    return (
        <div className="d-flex align-items-center gap-2 text-muted fs-13 py-2">
            <Spinner size="sm" />
            <span>A carregar dados de mercado...</span>
        </div>
    );
}

function TimedOutState({ onRetry }: { onRetry: () => void }) {
    return (
        <div className="text-center py-4">
            <i className="ri-time-line display-6 text-warning opacity-75" />
            <p className="mt-3 mb-1 fw-semibold">A análise está a demorar mais do que o esperado</p>
            <p className="text-muted fs-13 mb-3">
                O serviço de mercado pode estar momentaneamente lento. Tenta de novo em alguns minutos.
            </p>
            <button
                className="btn btn-sm btn-outline-primary"
                onClick={onRetry}
            >
                <i className="ri-refresh-line me-1" />
                Tentar novamente
            </button>
        </div>
    );
}

function NetworkErrorState({ onRetry }: { onRetry: () => void }) {
    return (
        <div className="text-center py-4">
            <i className="ri-wifi-off-line display-6 text-warning opacity-75" />
            <p className="mt-3 mb-1 fw-semibold">Não foi possível carregar os dados de mercado</p>
            <p className="text-muted fs-13 mb-3">
                Verifica a tua ligação e tenta de novo.
            </p>
            <button
                className="btn btn-sm btn-outline-primary"
                onClick={onRetry}
            >
                <i className="ri-refresh-line me-1" />
                Tentar novamente
            </button>
        </div>
    );
}

function Body({
    aggregate,
    refreshing,
    showComparables,
    onToggleComparables,
    onRefresh,
    pollAttempts,
    pollTimedOut,
    userRole,
    companyId,
    carId,
}: {
    aggregate: MarketAggregate | null | undefined;
    refreshing: boolean;
    showComparables: boolean;
    onToggleComparables: () => void;
    onRefresh: () => void;
    pollAttempts: number;
    pollTimedOut: boolean;
    userRole?: string;
    companyId: number;
    carId: number;
}) {
    if (pollTimedOut) {
        return <TimedOutState onRetry={onRefresh} />;
    }

    if (aggregate === null || aggregate === undefined) {
        return <NeverRunState onRefresh={onRefresh} refreshing={refreshing} />;
    }

    if (aggregate.status === "pending" || aggregate.status === "running") {
        return <PendingState pollAttempts={pollAttempts} />;
    }

    if (aggregate.status === "blocked") {
        return <BlockedState />;
    }

    // FASE 1b — ramo das AUTOCARAVANAS. Só desvia os estados com/sem dados
    // (success e vazio); pending/blocked/error genéricos ficam partilhados.
    // Deteção por `method` (aggregates calculados) OU `vehicle_type` (caso
    // guard-failed: motorhome sem tipologia, method ainda null). Os carros
    // (vehicle_type 'car', method null) nunca entram aqui → vista deles intacta.
    const isMotorhome =
        aggregate.method === MOTORHOME_METHOD || aggregate.vehicle_type === "motorhome";
    if (isMotorhome) {
        if (aggregate.status === "success" && aggregate.comparables_count > 0) {
            return (
                <MotorhomeSuccessBody
                    aggregate={aggregate}
                    showComparables={showComparables}
                    onToggleComparables={onToggleComparables}
                />
            );
        }
        // Distinção honesta da causa (o utilizador não deve confirmar uma
        // categoria que já lá está por causa de uma falha técnica):
        //   · 'error'  → falha TÉCNICA no cálculo → mensagem de erro + retry,
        //                nunca "confirma a categoria".
        //   · 'failed' → faltam DADOS de entrada (tipologia/ano) → ação do user.
        //   · 'none'   → correu e não há comparáveis → funil + link StandVirtual.
        if (aggregate.status === "error") {
            return <MotorhomeErrorState onRefresh={onRefresh} refreshing={refreshing} userRole={userRole} />;
        }
        if (
            aggregate.status === "none" ||
            aggregate.status === "failed" ||
            aggregate.comparables_count === 0
        ) {
            return (
                <MotorhomeEmptyState
                    aggregate={aggregate}
                    onRefresh={onRefresh}
                    refreshing={refreshing}
                />
            );
        }
    }

    if (aggregate.status === "error" || aggregate.status === "failed") {
        return <ErrorState userRole={userRole} />;
    }

    if (aggregate.status === "none" || aggregate.comparables_count === 0) {
        return (
            <NoneState
                onRefresh={onRefresh}
                refreshing={refreshing}
                carPrice={aggregate.comparison.car_price}
                hidePriceOnline={aggregate.hide_price_online ?? false}
            />
        );
    }

    const signal = aggregate.comparison.signal;
    const signalCfg = signal ? SIGNAL_CONFIG[signal] : null;
    const diffPct = aggregate.comparison.difference_percent;
    const isLowConfidence = aggregate.confidence === "low";
    const hasPromo = aggregate.comparison.car_price_gross !== undefined;

    return (
        <div>
            {isLowConfidence && (
                <Alert color="warning" className="fs-13 d-flex align-items-center gap-2">
                    <i className="ri-error-warning-line fs-16" />
                    Análise baseada em poucos dados — interpretar com precaução.
                </Alert>
            )}

            <div className="row g-3 mb-3">
                <div className="col-sm-4">
                    <MetricBox
                        label={hasPromo ? "Preço promo" : "O teu preço"}
                        value={formatCurrency(aggregate.comparison.car_price)}
                        hint={hasPromo ? `↑ PVP: ${formatCurrency(aggregate.comparison.car_price_gross ?? null)}` : undefined}
                    />
                </div>
                <div className="col-sm-4">
                    <MetricBox
                        label="Mediana mercado"
                        value={formatCurrency(aggregate.prices.median)}
                        // MS2.g item 4 — Em pesquisa alargada (fallback_used), o
                        // pool inclui modelos semelhantes (não idênticos) da mesma
                        // marca/categoria. "Alta" continua a referir-se ao volume
                        // de amostra; o sufixo "modelos semelhantes" sinaliza
                        // honestamente o que está dentro do pool.
                        hint={
                            (CONFIDENCE_LABEL[aggregate.confidence] ?? "—")
                            + " confiança"
                            + (aggregate.fallback_used ? " (modelos semelhantes)" : "")
                        }
                    />
                </div>
                <div className="col-sm-4">
                    <MetricBox
                        label="Diferença"
                        value={formatPercent(diffPct)}
                        badge={signalCfg ? { label: signalCfg.label, className: signalCfg.badgeClass } : undefined}
                    />
                </div>
            </div>

            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap pt-2 border-top">
                <div>
                    <div className="text-muted fs-12">
                        {/* MS2.f — atribuição da fonte:
                              · M fontes > 1 → "N anúncios · M fontes"
                              · M = 1        → "N anúncios · {NomeDaFonte}"
                              · Sem breakdown (legacy) → fallback "Standvirtual" */}
                        Análise baseada em {aggregate.comparables_count} anúncios · {
                            (() => {
                                const bd = aggregate.sources_breakdown;
                                const sourceKeys = bd ? Object.keys(bd).filter(k => (bd[k] ?? 0) > 0) : [];
                                if (sourceKeys.length > 1) {
                                    return `${sourceKeys.length} fontes`;
                                }
                                if (sourceKeys.length === 1) {
                                    return labelOf(sourceKeys[0], MARKET_SOURCE_LABELS);
                                }
                                return "Standvirtual";
                            })()
                        }
                        {aggregate.fallback_used && (
                            // MS2.g item 4 — tooltip explica o que "pesquisa
                            // alargada" significa em modelos fragmentados de
                            // autocaravanas (pool inclui modelos semelhantes
                            // da mesma marca/categoria, não modelo idêntico).
                            // `cursor: help` sinaliza visualmente que há mais.
                            <span
                                className="ms-2 badge bg-light text-muted"
                                style={{ cursor: "help" }}
                                title={
                                    "Não há anúncios suficientes deste modelo exacto à venda. " +
                                    "A análise usa autocaravanas semelhantes da mesma marca e " +
                                    "categoria (ex.: outras perfiladas/capucines da mesma marca " +
                                    "em anos próximos). Útil para posicionar o preço no mercado, " +
                                    "menos preciso que uma comparação modelo-a-modelo."
                                }
                            >
                                pesquisa alargada ⓘ
                            </span>
                        )}
                    </div>
                    {aggregate.top_comparables.length > 0 && (
                        <div className="text-muted fs-12">
                            A mostrar os {aggregate.top_comparables.length} mais próximos da mediana
                        </div>
                    )}
                </div>
                <button
                    className="btn btn-soft-secondary btn-sm"
                    onClick={onToggleComparables}
                >
                    <i className={`align-bottom me-1 ${showComparables ? "ri-arrow-up-s-line" : "ri-arrow-down-s-line"}`} />
                    {showComparables ? "Ocultar comparáveis" : "Ver comparáveis"}
                </button>
            </div>

            <Collapse isOpen={showComparables}>
                <ComparablesList
                    comparables={aggregate.top_comparables}
                    effectivePrice={aggregate.comparison.car_price}
                    searchUrl={aggregate.search_url}
                />
            </Collapse>
        </div>
    );
}

function NeverRunState({ onRefresh, refreshing }: { onRefresh: () => void; refreshing: boolean }) {
    return (
        <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap py-1">
            <span className="text-muted fs-13">Análise de mercado ainda não realizada.</span>
            <button
                className="btn btn-sm btn-primary"
                onClick={onRefresh}
                disabled={refreshing}
            >
                {refreshing ? <><Spinner size="sm" className="me-1" />A iniciar</> : "Analisar mercado"}
            </button>
        </div>
    );
}

// A busca de mercado corre um scrape (StandVirtual/CustoJusto) e pode demorar
// até uns minutos. Em vez de um spinner nu, damos: (1) uma etapa legível que
// avança com o tempo, (2) uma barra de progresso indeterminada, (3) o tempo
// decorrido e (4) a garantia de que pode sair — o resultado fica guardado
// (o poll persiste via sessionStorage). Copy genérico: serve carros e
// autocaravanas.
const PENDING_STEPS = [
    { until: 20, text: "A procurar anúncios semelhantes no mercado..." },
    { until: 60, text: "A comparar preços e a filtrar valores atípicos..." },
    { until: Infinity, text: "Quase lá — a consolidar os resultados..." },
];

function PendingState({ pollAttempts }: { pollAttempts: number }) {
    const elapsed = pollAttempts * (POLL_INTERVAL_MS / 1000);
    const step = PENDING_STEPS.find((s) => elapsed < s.until) ?? PENDING_STEPS[PENDING_STEPS.length - 1];

    return (
        <div className="py-2">
            <div className="d-flex align-items-center gap-2 text-body fs-13 mb-2">
                <Spinner size="sm" className="text-primary" />
                <span className="fw-medium">{step.text}</span>
                {elapsed > 0 && <span className="text-muted">({elapsed}s)</span>}
            </div>
            <Progress
                animated
                striped
                value={100}
                color="primary"
                style={{ height: 6 }}
                className="mb-2"
            />
            <p className="text-muted fs-12 mb-0">
                <i className="ri-information-line me-1" />
                Podes sair desta página — a análise continua e os resultados ficam guardados.
            </p>
        </div>
    );
}

function BlockedState() {
    return (
        <p className="text-muted fs-13 mb-0 py-1">
            Análise temporariamente indisponível. Vamos tentar novamente em breve.
        </p>
    );
}

function ErrorState({ userRole }: { userRole?: string }) {
    return (
        <div className="py-1">
            <p className="text-muted fs-13 mb-0">
                Não foi possível analisar o mercado para esta viatura.
            </p>
            {userRole === "root" && (
                <p className="text-danger fs-12 mb-0 mt-1">
                    [root] Estado: error/failed — verificar logs do worker.
                </p>
            )}
        </div>
    );
}

// MS1.c + MS2.g item 3 — mensagem accionável quando o aggregate está vazio.
// 4 variantes:
//   - preço ≤ 0 + hide_price_online === true:
//       nudge consciente do estado 'Sob consulta' (preço interno ≠ publicado)
//   - preço ≤ 0 + hide_price_online === false:
//       nudge directo para definir preço
//   - preço > 0 + comparables = 0 (MS2.g item 3 — "tentou mas vazio"):
//       texto orientador, sugere ao user verificar dados na Ficha
//   - (default — não usado actualmente, mas defensivo)
// O botão fica disabled quando noPrice (MS2.g item 2) — evita scrapes inúteis.
function NoneState({
    onRefresh,
    refreshing,
    carPrice,
    hidePriceOnline,
}: {
    onRefresh: () => void;
    refreshing: boolean;
    carPrice: number | null;
    hidePriceOnline: boolean;
}) {
    const noPrice = carPrice === null || carPrice <= 0;

    let message: string;
    if (noPrice && hidePriceOnline) {
        message = "Viatura 'Sob consulta' sem preço interno definido. Define um preço para activar a análise de mercado.";
    } else if (noPrice) {
        message = "Define um preço para esta viatura para activar a análise de mercado.";
    } else {
        // MS2.g item 3 — preço > 0 + scrape correu + 0 comparáveis: caso real
        // das McLouis gasolina / modelos invulgares. Orienta a verificar dados
        // em vez de parecer um erro técnico.
        message = "Não encontrámos viaturas semelhantes à venda neste momento. Pode ser um modelo invulgar no mercado — confirma na Ficha que o combustível e o ano estão correctos.";
    }

    // MS2.g item 2 — botão off quando não há preço. Tooltip explica porquê.
    const disabledReason = noPrice
        ? "Define um preço interno para esta viatura para activar a análise de mercado."
        : undefined;

    return (
        <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap py-1">
            <span className="text-muted fs-13">{message}</span>
            <button
                className="btn btn-sm btn-outline-secondary"
                onClick={onRefresh}
                disabled={refreshing || noPrice}
                title={disabledReason}
            >
                {refreshing ? <><Spinner size="sm" className="me-1" />A tentar</> : "Tentar novamente"}
            </button>
        </div>
    );
}

function MetricBox({
    label,
    value,
    hint,
    badge,
}: {
    label: string;
    value: string;
    hint?: string;
    badge?: { label: string; className: string };
}) {
    // Bloco de KPI com utilitários Velzon (border = var(--vz-border-color),
    // bg tertiary por token) — sem cores hardcoded. h-100 iguala a altura das
    // 3 colunas.
    return (
        <div className="border rounded p-3 h-100" style={{ background: "var(--vz-tertiary-bg)" }}>
            <span className="text-muted fs-12 d-block mb-1">{label}</span>
            <div className="fw-semibold fs-18 text-body">{value}</div>
            {hint && <span className="text-muted fs-11">{hint}</span>}
            {badge && (
                <span className={`badge rounded-pill px-2 py-1 fs-11 mt-1 d-inline-block ${badge.className}`}>
                    {badge.label}
                </span>
            )}
        </div>
    );
}

// ─── helpers ──────────────────────────────────────────────────────────────────

function formatCurrency(value: number | null): string {
    if (value === null || value === undefined) return "—";
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);
}

function formatPercent(value: number | null): string {
    if (value === null || value === undefined) return "—";
    const sign = value > 0 ? "+" : "";
    return `${sign}${value.toFixed(1)}%`;
}

// ─── sessionStorage helpers ───────────────────────────────────────────────────

interface StoredAggregate {
    aggregate_id: number;
    timestamp: number;
}

function storageKey(carId: number): string {
    return `xplendor:mkt_agg:${carId}`;
}

function readStoredAggregateId(carId: number): number | null {
    try {
        const raw = sessionStorage.getItem(storageKey(carId));
        if (!raw) return null;
        const entry = JSON.parse(raw) as StoredAggregate;
        if (Date.now() - entry.timestamp > STORAGE_TTL_MS) {
            sessionStorage.removeItem(storageKey(carId));
            return null;
        }
        return entry.aggregate_id;
    } catch {
        return null;
    }
}

function writeStoredAggregateId(carId: number, aggregateId: number): void {
    try {
        const entry: StoredAggregate = { aggregate_id: aggregateId, timestamp: Date.now() };
        sessionStorage.setItem(storageKey(carId), JSON.stringify(entry));
    } catch {
        // sessionStorage blocked (private mode, storage full) — degrade gracefully
    }
}

function clearStoredAggregateId(carId: number): void {
    try {
        sessionStorage.removeItem(storageKey(carId));
    } catch {
        // ignore
    }
}
