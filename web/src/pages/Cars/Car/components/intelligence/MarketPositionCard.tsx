import { useEffect, useRef, useState } from "react";
import { Spinner } from "reactstrap";
import { toast } from "react-toastify";
import type {
    MarketAggregate,
    MarketAggregateStatus,
    MarketPriceSignal,
} from "../../../../../types/api";
import { fetchMarketAggregate, refreshMarketAggregate } from "../../../../../helpers/marketAggregate_helper";
import ComparablesList from "./ComparablesList";

const POLL_INTERVAL_MS  = 10_000;
const MAX_POLL_ATTEMPTS = 18; // 3 minutes

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
    const [aggregate, setAggregate]           = useState<MarketAggregate | null | undefined>(undefined);
    const [loadingInitial, setLoadingInitial] = useState(true);
    const [refreshing, setRefreshing]         = useState(false);
    const [showComparables, setShowComparables] = useState(false);
    const [pollAttempts, setPollAttempts]     = useState(0);
    const [pollTimedOut, setPollTimedOut]     = useState(false);
    const [networkError, setNetworkError]     = useState(false);

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

    const startPolling = () => {
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
                const data = await fetchMarketAggregate(companyId, carId);
                if (data && TERMINAL_STATUSES.includes(data.status)) {
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

        fetchMarketAggregate(companyId, carId)
            .then((data) => {
                if (cancelled) return;
                setNetworkError(false);
                setAggregate(data);

                if (data && !TERMINAL_STATUSES.includes(data.status)) {
                    startPolling();
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
            await refreshMarketAggregate(companyId, carId);
            if (!mountedRef.current) return;
            toast.success("Análise de mercado iniciada. Os resultados aparecem em breve.");
            startPolling();
        } catch (err: unknown) {
            if (!mountedRef.current) return;
            setRefreshing(false);
            const status = (err as { response?: { status?: number } })?.response?.status;
            if (status === 429) {
                toast.warning("Aguarda alguns minutos antes de actualizar novamente.");
            } else if (status === 422) {
                toast.info("Este tipo de viatura não suporta análise de mercado.");
            } else {
                toast.error("Não foi possível iniciar a análise de mercado.");
            }
        }
    };

    const retryFetch = async () => {
        setLoadingInitial(true);
        setNetworkError(false);

        try {
            const data = await fetchMarketAggregate(companyId, carId);
            setNetworkError(false);
            setAggregate(data);
            if (data && !TERMINAL_STATUSES.includes(data.status)) {
                startPolling();
            }
        } catch {
            setNetworkError(true);
        } finally {
            setLoadingInitial(false);
        }
    };

    return (
        <section style={sectionStyle}>
            <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                <p className="text-muted text-uppercase fw-semibold fs-11 mb-0" style={{ letterSpacing: "0.08em" }}>
                    Posição no mercado
                </p>
                {(pollTimedOut || (aggregate && TERMINAL_STATUSES.includes(aggregate.status))) && (
                    <button
                        className="btn btn-sm btn-outline-secondary py-1 px-2 fs-12"
                        onClick={handleRefresh}
                        disabled={refreshing}
                    >
                        {refreshing
                            ? <><Spinner size="sm" className="me-1" />A actualizar</>
                            : "↻ Actualizar agora"}
                    </button>
                )}
            </div>

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
                    />
            }
        </section>
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
}: {
    aggregate: MarketAggregate | null | undefined;
    refreshing: boolean;
    showComparables: boolean;
    onToggleComparables: () => void;
    onRefresh: () => void;
    pollAttempts: number;
    pollTimedOut: boolean;
    userRole?: string;
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

    if (aggregate.status === "error" || aggregate.status === "failed") {
        return <ErrorState userRole={userRole} />;
    }

    if (aggregate.status === "none" || aggregate.comparables_count === 0) {
        return <NoneState onRefresh={onRefresh} refreshing={refreshing} />;
    }

    const signal = aggregate.comparison.signal;
    const signalCfg = signal ? SIGNAL_CONFIG[signal] : null;
    const diffPct = aggregate.comparison.difference_percent;
    const isLowConfidence = aggregate.confidence === "low";

    return (
        <div>
            {isLowConfidence && (
                <div className="mb-3 px-3 py-2 rounded-3 fs-13 bg-warning-subtle text-warning" style={{ border: "1px solid #ffc10720" }}>
                    Análise baseada em poucos dados — interpretar com precaução.
                </div>
            )}

            <div className="row g-3 mb-3">
                <div className="col-sm-4">
                    <MetricBox
                        label="O teu preço"
                        value={formatCurrency(aggregate.comparison.car_price)}
                    />
                </div>
                <div className="col-sm-4">
                    <MetricBox
                        label="Mediana mercado"
                        value={formatCurrency(aggregate.prices.median)}
                        hint={`${CONFIDENCE_LABEL[aggregate.confidence] ?? "—"} confiança`}
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

            <div
                className="d-flex align-items-center justify-content-between gap-2 flex-wrap pt-2"
                style={{ borderTop: "1px solid #eef0f2" }}
            >
                <div className="text-muted fs-12">
                    Baseado em {aggregate.comparables_count} comparáveis · Standvirtual
                    {aggregate.fallback_used && (
                        <span className="ms-2 badge bg-light text-muted">pesquisa alargada</span>
                    )}
                </div>
                <button
                    className="btn btn-link btn-sm p-0 text-decoration-none fs-12"
                    onClick={onToggleComparables}
                >
                    {showComparables ? "Ocultar comparáveis ↑" : "Ver comparáveis ↓"}
                </button>
            </div>

            {showComparables && (
                <ComparablesList comparables={aggregate.top_comparables} />
            )}
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

function PendingState({ pollAttempts }: { pollAttempts: number }) {
    const elapsed = pollAttempts * (POLL_INTERVAL_MS / 1000);
    return (
        <div className="d-flex align-items-center gap-2 text-muted fs-13 py-2">
            <Spinner size="sm" />
            <span>
                A analisar mercado...
                {elapsed > 0 && ` (${elapsed}s)`}
            </span>
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

function NoneState({ onRefresh, refreshing }: { onRefresh: () => void; refreshing: boolean }) {
    return (
        <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap py-1">
            <span className="text-muted fs-13">
                Sem comparáveis disponíveis no mercado para este modelo no momento.
            </span>
            <button
                className="btn btn-sm btn-outline-secondary"
                onClick={onRefresh}
                disabled={refreshing}
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
    return (
        <div
            style={{
                padding: "12px 14px",
                borderRadius: "12px",
                border: "1px solid #eef0f2",
                background: "#fcfcfd",
            }}
        >
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

const sectionStyle: React.CSSProperties = {
    padding: "16px 18px",
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
};
