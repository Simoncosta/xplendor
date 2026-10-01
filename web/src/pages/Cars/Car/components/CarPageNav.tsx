import classnames from "classnames";
import { Link, useParams } from "react-router-dom";
import { useSelector } from "react-redux";
import { Nav, NavItem, NavLink } from "reactstrap";

type NavPage = "analytics" | "intelligence" | "ficha" | "documents";

const pages: { key: NavPage; label: string; icon: string }[] = [
    { key: "analytics", label: "Tráfego & Canais", icon: "ri-bar-chart-grouped-line" },
    { key: "intelligence", label: "Mercado & Público", icon: "ri-cpu-line" },
    { key: "ficha", label: "Ficha", icon: "ri-car-line" },
    { key: "documents", label: "Documentos", icon: "ri-file-text-line" },
];

export default function CarPageNav({ active }: { active: NavPage }) {
    const { id } = useParams();
    const carAnalytics = useSelector((state: any) => state.Car?.data?.carAnalytics);

    const metrics = carAnalytics?.metrics;
    const ips = carAnalytics?.potential_score;

    // NOTA sobre o "Custom Nav" chanfrado do Velzon (.nav-customs): esse estilo
    // foi desenhado para tabs de CONTEÚDO curtas num card de largura fixa —
    // usa float:right (inverte a ordem), overflow:hidden (corta no telemóvel) e
    // cunhas ::before/::after com skew dimensionadas para rótulos curtos. Com os
    // nossos rótulos longos + badges + navegação por ROTA + scroll no telemóvel
    // partia-se. Usamos o modelo robusto mais próximo de "separadores/páginas
    // abertas": o nav-tabs clássico do Velzon (o separador activo salta à frente
    // como uma página aberta), com cada tab a ser um <Link> de rota.
    return (
        <div style={{ overflowX: "auto" }}>
            <Nav tabs className="nav-tabs-custom-pages flex-nowrap" style={{ minWidth: "max-content" }}>
                {pages.map((p) => {
                    const isActive = p.key === active;
                    const context = getPageContext(p.key, {
                        views: Number(metrics?.views || 0),
                        ipsScore: ips?.score,
                    });

                    return (
                        <NavItem key={p.key}>
                            <NavLink
                                tag={Link}
                                to={`/cars/${id}/${p.key}`}
                                active={isActive}
                                className={classnames(
                                    "d-inline-flex align-items-center gap-2 text-nowrap",
                                    { "text-body": !isActive }
                                )}
                            >
                                <i className={p.icon} />
                                {p.label}
                                {context && (
                                    <span className={`badge rounded-pill px-2 py-1 fs-11 ${context.className}`}>
                                        {context.label}
                                    </span>
                                )}
                            </NavLink>
                        </NavItem>
                    );
                })}
            </Nav>
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
