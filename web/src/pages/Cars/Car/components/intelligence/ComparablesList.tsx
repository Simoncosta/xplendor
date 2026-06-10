import { toast } from "react-toastify";
import type { MarketComparable } from "../../../../../types/api";

// MS1.e (2026-06-10) — o ↗ abre o anúncio individual directamente em nova aba.
// O endpoint check-link foi removido do caminho do clique: o seu helper estava
// a fazer `res.data?.available` quando o interceptor Axios já desempacota o
// body (mesmo padrão do X7.1/T2), resultando sempre em `false` e divergindo
// para search_url mesmo em anúncios vivos. Mesmo corrigindo isso, fail-open
// para search_url perante ambiguidade (timeout, 403 anti-bot ocasional, etc.)
// tirava ao utilizador o destino certo na maioria dos cliques. Comportamento
// novo:
//   - url do comparável não vazio → abre directamente. Sem pré-flight.
//   - url vazio (snapshot legacy) → abre searchUrl + toast informativo.
// Anúncios vendidos no Standvirtual levam o utilizador à página "já não
// disponível" do próprio Standvirtual — honesto e mais útil que nunca chegar
// ao anúncio. Endpoint check-link mantém-se para reuso futuro mas sai do
// fluxo de UI desta secção.

interface Props {
    comparables: MarketComparable[];
    effectivePrice: number | null;
    searchUrl: string | null;
}

const FUEL_LABELS: Record<string, string> = {
    gasoline:      "Gasolina",
    diesel:        "Diesel",
    electric:      "Elétrico",
    hybrid:        "Híbrido",
    plugin_hybrid: "Plug-in",
    lpg:           "GPL",
    cng:           "GNV",
};

const GEARBOX_LABELS: Record<string, string> = {
    manual:         "Manual",
    automatic:      "Auto",
    semi_automatic: "Semi-auto",
};

function formatCurrency(value: number): string {
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(value);
}

function openSearchFallback(searchUrl: string | null): void {
    const target = searchUrl ?? "https://www.standvirtual.com/";
    window.open(target, "_blank", "noopener,noreferrer");
    toast.info(
        "Sem link directo para este anúncio. Abrimos uma pesquisa por viaturas similares no Standvirtual.",
        { autoClose: 6000 }
    );
}

export default function ComparablesList({
    comparables,
    effectivePrice,
    searchUrl,
}: Props) {
    if (comparables.length === 0) {
        return (
            <p className="text-muted fs-13 mb-0 mt-2">
                Sem comparáveis disponíveis para apresentar.
            </p>
        );
    }

    return (
        <div className="mt-3" style={{ borderTop: "1px solid #eef0f2", paddingTop: 12 }}>
            <div className="d-flex flex-column gap-2">
                {comparables.map((item, i) => {
                    const chips = [
                        item.fuel    ? (FUEL_LABELS[item.fuel]       ?? item.fuel)    : null,
                        item.gearbox ? (GEARBOX_LABELS[item.gearbox] ?? item.gearbox) : null,
                        item.region  ?? null,
                        item.year    ? String(item.year) : null,
                    ].filter(Boolean).join(" · ");

                    const diffPct = effectivePrice && effectivePrice > 0
                        ? ((item.price - effectivePrice) / effectivePrice) * 100
                        : null;

                    const hasUrl = !!item.url;

                    return (
                        <div
                            key={i}
                            className="d-flex align-items-start justify-content-between gap-3 rounded-3 px-3 py-2"
                            style={{ background: "#f8fafc", border: "1px solid #eef0f2" }}
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
                                    {hasUrl ? (
                                        // Link nativo do browser: abre o anúncio individual em nova
                                        // aba sem pré-flight nem JS. noopener+noreferrer por segurança.
                                        <a
                                            href={item.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-muted fs-11 text-decoration-none flex-shrink-0"
                                            style={{ lineHeight: 1, cursor: "pointer" }}
                                            aria-label="Ver anúncio no Standvirtual"
                                        >
                                            ↗
                                        </a>
                                    ) : (
                                        // Snapshot legacy sem url: abre a pesquisa pré-computada e
                                        // sinaliza ao utilizador via toast.
                                        <a
                                            href="#"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                openSearchFallback(searchUrl);
                                            }}
                                            className="text-muted fs-11 text-decoration-none flex-shrink-0"
                                            style={{ lineHeight: 1, cursor: "pointer" }}
                                            aria-label="Pesquisar viaturas similares no Standvirtual"
                                        >
                                            ↗
                                        </a>
                                    )}
                                </div>
                                {chips && (
                                    <span className="text-muted fs-12">{chips}</span>
                                )}
                            </div>

                            <div className="text-end flex-shrink-0">
                                <div className="fw-semibold fs-14">{formatCurrency(item.price)}</div>
                                {diffPct !== null && (
                                    <span className={`fs-11 ${diffPct > 0 ? "text-danger" : "text-success"}`}>
                                        {diffPct > 0 ? "+" : ""}{diffPct.toFixed(1)}% vs teu preço
                                    </span>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
