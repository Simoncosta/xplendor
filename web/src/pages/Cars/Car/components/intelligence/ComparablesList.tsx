import { useRef, useState } from "react";
import { toast } from "react-toastify";
import type { MarketComparable } from "../../../../../types/api";
import { checkMarketLink } from "../../../../../helpers/marketAggregate_helper";

interface Props {
    comparables: MarketComparable[];
    effectivePrice: number | null;
    companyId: number;
    carId: number;
    searchUrl: string | null;
}

interface LinkCacheEntry {
    available: boolean;
    expiresAt: number;
}

const LINK_CACHE_TTL_MS = 60_000; // 60 seconds per URL

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

export default function ComparablesList({
    comparables,
    effectivePrice,
    companyId,
    carId,
    searchUrl,
}: Props) {
    const linkCache = useRef<Map<string, LinkCacheEntry>>(new Map());
    const [checkingUrl, setCheckingUrl] = useState<string | null>(null);

    const handleLinkClick = async (e: React.MouseEvent<HTMLAnchorElement>, url: string) => {
        e.preventDefault();

        const now = Date.now();
        const cached = linkCache.current.get(url);

        if (cached && cached.expiresAt > now) {
            if (cached.available) {
                window.open(url, "_blank", "noopener,noreferrer");
            } else {
                openFallback(url, searchUrl);
            }
            return;
        }

        setCheckingUrl(url);
        try {
            const available = await checkMarketLink(companyId, carId, url);
            linkCache.current.set(url, { available, expiresAt: now + LINK_CACHE_TTL_MS });

            if (available) {
                window.open(url, "_blank", "noopener,noreferrer");
            } else {
                openFallback(url, searchUrl);
            }
        } catch {
            // Network error — fail-open to search URL
            openFallback(url, searchUrl);
        } finally {
            setCheckingUrl(null);
        }
    };

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

                    const isChecking = checkingUrl === item.url;

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
                                    <a
                                        href={item.url}
                                        onClick={(e) => handleLinkClick(e, item.url)}
                                        className="text-muted fs-11 text-decoration-none flex-shrink-0"
                                        style={{
                                            lineHeight: 1,
                                            cursor: isChecking ? "wait" : "pointer",
                                            opacity: isChecking ? 0.5 : 1,
                                        }}
                                        aria-label="Ver anúncio no Standvirtual"
                                    >
                                        {isChecking ? "…" : "↗"}
                                    </a>
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

function openFallback(deadUrl: string, searchUrl: string | null): void {
    const target = searchUrl ?? `https://www.standvirtual.com/carros`;
    window.open(target, "_blank", "noopener,noreferrer");
    toast.info(
        "Este anúncio já não está disponível. Abrimos uma pesquisa por carros similares no Standvirtual.",
        { autoClose: 6000 }
    );
}
