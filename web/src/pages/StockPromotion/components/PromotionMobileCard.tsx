import { useNavigate } from "react-router-dom";
import StarToggle from "./StarToggle";
import MarketChip from "./MarketChip";
import CarThumbnail from "Components/Common/CarThumbnail";
import { formatIpsBadge } from "../../../helpers/ips";
import { labelOf, VEHICLE_TYPE_LABELS } from "../../../helpers/labels";
import type { PromotionCandidate } from "../../../types/api";

/**
 * Mobile card — espelha o `renderCarMobileCard` da CarList em estrutura
 * e estilo (border radius 16, thumbnail 16:9 no topo, padding 12/14, chips
 * em row com border + bg pálido, footer com data + acção).
 *
 * Diferenças (porque o domínio é outro):
 *   - 3 chips no row são "Mercado · IPS · Preço" (em vez de Views/Leads/Conversão)
 *   - Estrela ★/☆ no footer à direita (em vez dos botões Editar/Inteligência)
 *   - Sem badge de atenção própria — `is_stale` mostra-se como badge no topo
 */
interface Props {
    candidate: PromotionCandidate;
    companyId: number;
    onPriorityChanged: (carId: number, next: PromotionCandidate["promotion"]) => void;
}

const formatEuro = (v: number | null): string => {
    if (v === null) return "—";
    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(v);
};

const PromotionMobileCard = ({ candidate, companyId, onPriorityChanged }: Props) => {
    const navigate = useNavigate();
    const ips = formatIpsBadge(candidate.ips?.score, candidate.ips?.classification);
    const title = [candidate.brand?.name, candidate.model?.name].filter(Boolean).join(" ");

    const isHabitation =
        candidate.vehicle_type === "motorhome" || candidate.vehicle_type === "caravan";
    const taxonomy = isHabitation
        ? candidate.category?.name
        : (candidate.segment && candidate.segment !== candidate.vehicle_type ? candidate.segment : null);
    const metaLine = [
        labelOf(candidate.vehicle_type, VEHICLE_TYPE_LABELS),
        taxonomy,
        candidate.engine_brand ? `motor ${candidate.engine_brand}` : null,
        candidate.registration_year ? String(candidate.registration_year) : null,
    ].filter(Boolean).join(" · ");

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => navigate(`/cars/${candidate.id}/ficha`)}
            onKeyDown={(e) => { if (e.key === "Enter") navigate(`/cars/${candidate.id}/ficha`); }}
            style={{
                background: "#fff",
                border: "1px solid #e9ebec",
                borderRadius: 16,
                overflow: "hidden",
                cursor: "pointer",
            }}
        >
            <CarThumbnail src={candidate.thumbnail} variant="fullwidth" />

            <div style={{ padding: "12px 14px" }}>
                {/* Título + badge "Parada há muito" se aplicável */}
                <div className="d-flex align-items-center gap-2 mb-2" style={{ minWidth: 0 }}>
                    <h6 className="mb-0 fw-semibold text-body text-truncate" style={{ minWidth: 0, flex: 1 }}>
                        {title || "Sem nome"}
                    </h6>
                    {candidate.is_stale && (
                        <span className="badge rounded-pill px-2 py-1 fs-11 flex-shrink-0 bg-warning-subtle text-warning">
                            <i className="ri-time-line me-1" />
                            Parada
                        </span>
                    )}
                </div>

                {metaLine && (
                    <p className="text-muted mb-2 fs-12">{metaLine}</p>
                )}

                {/* Preço — destaque */}
                <div className="mb-3">
                    <div className="d-flex align-items-baseline gap-2">
                        <span className="fw-bold fs-15">{formatEuro(candidate.price.effective)}</span>
                        {candidate.price.has_promo && (
                            <span className="text-muted text-decoration-line-through fs-12">
                                {formatEuro(candidate.price.gross)}
                            </span>
                        )}
                    </div>
                </div>

                {/* 3 chips no row — espelha o padrão de stat-chips da CarList */}
                <div className="d-flex gap-2 mb-3">
                    <div
                        style={{
                            flex: 1,
                            border: "1px solid #e9ebec",
                            borderRadius: 8,
                            padding: "5px 8px",
                            background: "#f8fafc",
                            textAlign: "center",
                        }}
                    >
                        <div
                            className="text-muted fw-semibold text-uppercase"
                            style={{ fontSize: 10, letterSpacing: "0.06em", marginBottom: 2 }}
                        >
                            Em stock
                        </div>
                        <div className="fw-semibold fs-13 text-body">
                            {candidate.days_in_stock} {candidate.days_in_stock === 1 ? "dia" : "dias"}
                        </div>
                    </div>
                    <div
                        style={{
                            flex: 1,
                            border: "1px solid #e9ebec",
                            borderRadius: 8,
                            padding: "5px 8px",
                            background: "#f8fafc",
                            textAlign: "center",
                        }}
                    >
                        <div
                            className="text-muted fw-semibold text-uppercase"
                            style={{ fontSize: 10, letterSpacing: "0.06em", marginBottom: 2 }}
                        >
                            Tráfego
                        </div>
                        <div className={`fw-semibold fs-13 ${candidate.engagement.leads_count > 0 ? "text-success" : "text-body"}`}>
                            {candidate.engagement.views_count}v · {candidate.engagement.leads_count}l
                        </div>
                    </div>
                    <div
                        style={{
                            flex: 1,
                            border: "1px solid #e9ebec",
                            borderRadius: 8,
                            padding: "5px 8px",
                            background: "#f8fafc",
                            textAlign: "center",
                        }}
                    >
                        <div
                            className="text-muted fw-semibold text-uppercase"
                            style={{ fontSize: 10, letterSpacing: "0.06em", marginBottom: 2 }}
                        >
                            IPS
                        </div>
                        <div className="fw-semibold fs-13 text-body">{ips.shortLabel}</div>
                    </div>
                </div>

                {/* Chip de mercado autónomo — ocupa linha inteira porque carrega contexto */}
                <div className="mb-3">
                    <MarketChip market={candidate.market} rowId={`m-${candidate.id}`} />
                </div>

                {/* Footer: marcado-por (subtil) + estrela à direita */}
                <div style={{ borderTop: "1px solid #e9ebec", paddingTop: 10 }}>
                    <div className="d-flex align-items-center justify-content-between">
                        <span className="text-muted fs-12">
                            {candidate.promotion ? (
                                <>
                                    Marcada
                                    {candidate.promotion.marked_by ? ` por ${candidate.promotion.marked_by.name}` : ""}
                                </>
                            ) : (
                                "Sem marcação"
                            )}
                        </span>
                        <StarToggle
                            companyId={companyId}
                            carId={candidate.id}
                            initial={candidate.promotion}
                            onChange={(next) => onPriorityChanged(candidate.id, next)}
                            size="lg"
                        />
                    </div>
                </div>
            </div>
        </div>
    );
};

export default PromotionMobileCard;
