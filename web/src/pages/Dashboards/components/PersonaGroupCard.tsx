import { Link } from "react-router-dom";

export interface PersonaCar {
    id: number;
    brand_name: string | null;
    model_name: string | null;
    version: string | null;
    price_gross: number;
    ips_score: number | null;
    ips_classification: "hot" | "warm" | "cold" | null;
    views_count: number;
    leads_count: number;
    interactions_count: number;
}

export interface PersonaGroup {
    persona: "executivo" | "familia" | "jovem" | "performance" | "geral";
    label: string;
    description: string;
    cars_count: number;
    avg_ips: number | null;
    total_views: number;
    total_leads: number;
    interactions_count: number;
    is_campanha_viable: boolean;
    cars: PersonaCar[];
}

interface Props {
    group: PersonaGroup;
}

const personaIcon: Record<PersonaGroup["persona"], string> = {
    executivo: "ri-briefcase-4-line",
    familia: "ri-home-heart-line",
    jovem: "ri-flashlight-line",
    performance: "ri-speed-up-line",
    geral: "ri-car-line",
};

const personaBg: Record<PersonaGroup["persona"], string> = {
    executivo: "bg-primary-subtle",
    familia: "bg-success-subtle",
    jovem: "bg-info-subtle",
    performance: "bg-warning-subtle",
    geral: "bg-light",
};

const ipsClass = (cls: string | null) => {
    if (cls === "hot") return "bg-success-subtle text-success";
    if (cls === "warm") return "bg-warning-subtle text-warning";
    return "bg-danger-subtle text-danger";
};

const formatCurrency = (value: number) =>
    value.toLocaleString("pt-PT", { style: "currency", currency: "EUR", maximumFractionDigits: 0 });

export default function PersonaGroupCard({ group }: Props) {
    const icon = personaIcon[group.persona];
    const bg = personaBg[group.persona];

    return (
        <div
            style={{
                border: "1px solid #e9ebec",
                borderRadius: 16,
                background: "#fff",
                overflow: "hidden",
                opacity: group.is_campanha_viable ? 1 : 0.65,
            }}
        >
            {/* Header */}
            <div className={`d-flex align-items-center justify-content-between gap-3 flex-wrap px-4 py-3 ${bg}`} style={{ borderBottom: "1px solid #e9ebec" }}>
                <div className="d-flex align-items-center gap-3">
                    <div
                        className="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        style={{ width: 40, height: 40, background: "rgba(255,255,255,0.6)" }}
                    >
                        <i className={`${icon} fs-18`} />
                    </div>
                    <div>
                        <h6 className="mb-0 fw-semibold">{group.label}</h6>
                        <span className="text-muted fs-12">{group.cars_count} carro{group.cars_count !== 1 ? "s" : ""}{group.avg_ips !== null ? ` · IPS médio ${group.avg_ips}` : ""}</span>
                    </div>
                </div>
                {group.is_campanha_viable ? (
                    <span className="badge bg-success-subtle text-success fs-11 px-3 py-2 rounded-pill">
                        <i className="ri-check-line me-1" />
                        Campanha conjunta viável
                    </span>
                ) : (
                    <span className="badge bg-secondary-subtle text-secondary fs-11 px-3 py-2 rounded-pill">
                        Grupo insuficiente
                    </span>
                )}
            </div>

            <div style={{ padding: "14px 20px" }}>
                <p className="text-muted fs-13 mb-3">{group.description}</p>

                {/* Car list */}
                <div className="vstack gap-2 mb-3">
                    {group.cars.map((car) => (
                        <div
                            key={car.id}
                            className="d-flex align-items-center justify-content-between gap-3 flex-wrap rounded-3 px-3 py-2"
                            style={{ background: "#f8fafc", border: "1px solid #e9ebec" }}
                        >
                            <div className="d-flex align-items-center gap-2 flex-wrap">
                                <span className="fw-medium fs-13">
                                    {car.brand_name} {car.model_name}
                                    {car.version && <span className="text-muted fw-normal"> {car.version}</span>}
                                </span>
                                <span className="text-muted fs-13">{formatCurrency(car.price_gross)}</span>
                                {car.ips_score !== null && (
                                    <span className={`badge rounded-pill fs-11 px-2 py-1 ${ipsClass(car.ips_classification)}`}>
                                        {car.ips_score}
                                    </span>
                                )}
                            </div>
                            <div className="d-flex align-items-center gap-3 flex-shrink-0">
                                <span className="text-muted fs-12">
                                    <i className="ri-eye-line me-1" />{car.views_count} views
                                </span>
                                <span className="text-muted fs-12">
                                    <i className="ri-chat-3-line me-1" />
                                    {car.interactions_count}
                                </span>
                                <span className="text-muted fs-12">
                                    <i className="ri-user-follow-line me-1" />{car.leads_count} lead{car.leads_count !== 1 ? "s" : ""}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Footer */}
                <div className="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <span className="text-muted fs-12">
                        {group.total_views.toLocaleString("pt-PT")} views totais · {group.total_leads} lead{group.total_leads !== 1 ? "s" : ""}
                        · {group.interactions_count} interações
                        {group.is_campanha_viable
                            ? <span className="text-success ms-2"><i className="ri-checkbox-circle-line me-1" />Campanha conjunta viável</span>
                            : <span className="text-muted ms-2">· Adiciona mais carros deste perfil para criar campanha conjunta</span>
                        }
                    </span>
                    <div className="d-flex gap-2">
                        <Link to="/cars" className="btn btn-sm btn-light">
                            Ver carros
                        </Link>
                        {group.is_campanha_viable && (
                            <Link to={`/cars/${group.cars[0]?.id}/marketing`} className="btn btn-sm btn-soft-primary">
                                Criar narrativa <i className="ri-arrow-right-line ms-1" />
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
