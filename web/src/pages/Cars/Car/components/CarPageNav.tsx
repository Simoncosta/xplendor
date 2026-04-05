import { Link, useParams } from "react-router-dom";
import { useSelector } from "react-redux";

type NavPage = "analytics" | "intelligence" | "marketing" | "ads" | "ficha";

const pages: { key: NavPage; label: string; icon: string }[] = [
    { key: "analytics", label: "Tráfego & Canais", icon: "ri-bar-chart-grouped-line" },
    { key: "intelligence", label: "Mercado & Público", icon: "ri-cpu-line" },
    { key: "marketing", label: "Conteúdo da semana", icon: "ri-megaphone-line" },
    { key: "ads", label: "Decisão de investimento", icon: "ri-money-euro-circle-line" },
    { key: "ficha", label: "Ficha", icon: "ri-car-line" },
];

export default function CarPageNav({ active }: { active: NavPage }) {
    const { id } = useParams();
    const carAnalytics = useSelector((state: any) => state.Car?.data?.carAnalytics);
    const carMarketing = useSelector((state: any) => state.Car?.data?.carMarketing);

    const metrics = carAnalytics?.metrics;
    const ips = carAnalytics?.potential_score;
    const paidChannel = (carAnalytics?.performance?.by_channel || []).find((item: any) => item?.channel === "paid");
    const weeklySpend = Number(paidChannel?.total_spend || 0);
    const ideas = carMarketing?.marketing_ideas || [];
    const hasNewMarketingIdea = ideas.some((idea: any) => idea?.status && idea.status !== "used");

    return (
        <div
            style={{
                border: "1px solid #e9ebec",
                borderRadius: "14px",
                background: "#fff",
                padding: "6px",
                display: "flex",
                gap: "4px",
                flexWrap: "wrap",
            }}
        >
            {pages.map((p) => {
                const isActive = p.key === active;
                const context = getPageContext(p.key, {
                    views: Number(metrics?.views || 0),
                    ipsScore: ips?.score,
                    weeklySpend,
                    hasNewMarketingIdea,
                });

                return (
                    <Link
                        key={p.key}
                        to={`/cars/${id}/${p.key}`}
                        className="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-3 text-decoration-none fs-13"
                        style={{
                            fontWeight: isActive ? 600 : 400,
                            color: isActive ? "#405189" : "#878a99",
                            background: isActive ? "#f8f9fa" : "transparent",
                            border: isActive ? "1px solid #e9ebec" : "1px solid transparent",
                        }}
                    >
                        <i className={p.icon} />
                        {p.label}
                        {context && (
                            <span className={`badge rounded-pill px-2 py-1 fs-11 ${context.className}`}>
                                {context.label}
                            </span>
                        )}
                    </Link>
                );
            })}
        </div>
    );
}

function getPageContext(
    page: NavPage,
    data: {
        views: number;
        ipsScore?: number | null;
        weeklySpend: number;
        hasNewMarketingIdea: boolean;
    }
) {
    switch (page) {
        case "analytics":
            return data.views > 0
                ? { label: `${data.views} views`, className: "bg-info-subtle text-info" }
                : null;
        case "intelligence":
            return data.ipsScore
                ? { label: `IPS ${data.ipsScore}`, className: data.ipsScore > 70 ? "bg-success-subtle text-success" : data.ipsScore >= 40 ? "bg-warning-subtle text-warning" : "bg-danger-subtle text-danger" }
                : null;
        case "marketing":
            return data.hasNewMarketingIdea
                ? { label: "Novo", className: "bg-warning-subtle text-warning" }
                : null;
        case "ads":
            return data.weeklySpend > 0
                ? { label: new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(data.weeklySpend), className: "bg-info-subtle text-info" }
                : { label: "Sem investimento activo", className: "bg-secondary-subtle text-secondary" };
        default:
            return null;
    }
}
