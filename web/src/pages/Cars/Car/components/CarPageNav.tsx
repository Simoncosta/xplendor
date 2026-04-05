import { Link, useParams } from "react-router-dom";

type NavPage = "analytics" | "intelligence" | "marketing" | "ads" | "ficha";

const pages: { key: NavPage; label: string; icon: string }[] = [
    { key: "analytics", label: "Analytics", icon: "ri-bar-chart-grouped-line" },
    { key: "intelligence", label: "Inteligência", icon: "ri-cpu-line" },
    { key: "marketing", label: "Marketing", icon: "ri-megaphone-line" },
    { key: "ads", label: "Ads", icon: "ri-money-euro-circle-line" },
    { key: "ficha", label: "Ficha", icon: "ri-car-line" },
];

export default function CarPageNav({ active }: { active: NavPage }) {
    const { id } = useParams();

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
                    </Link>
                );
            })}
        </div>
    );
}
