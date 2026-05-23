import { Link, useParams } from "react-router-dom";
import { useSelector } from "react-redux";

type NavPage = "analytics" | "intelligence" | "ficha";

const pages: { key: NavPage; label: string; icon: string }[] = [
    { key: "analytics", label: "Tráfego & Canais", icon: "ri-bar-chart-grouped-line" },
    { key: "intelligence", label: "Mercado & Público", icon: "ri-cpu-line" },
    { key: "ficha", label: "Ficha", icon: "ri-car-line" },
];

export default function CarPageNav({ active }: { active: NavPage }) {
    const { id } = useParams();
    const carAnalytics = useSelector((state: any) => state.Car?.data?.carAnalytics);

    const metrics = carAnalytics?.metrics;
    const ips = carAnalytics?.potential_score;

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
        default:
            return null;
    }
}
