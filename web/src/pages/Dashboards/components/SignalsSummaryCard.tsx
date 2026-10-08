import { Link } from "react-router-dom";
import { Card, CardBody, CardHeader } from "reactstrap";
import { RestaurantSignalsData } from "common/models/pingwin.model";
import { ConfidenceBadge, fmtDay, fmtWeekday } from "pages/Marketing/SignalCard";

/**
 * XPLENDOR — F3 §3: no separador "Marketing" do dashboard de restauração, as 3 sugestões
 * principais de "O que publicar e quando", com ligação ao painel. Não aparece sem sugestões
 * ou com o interruptor desligado.
 */
export default function SignalsSummaryCard({ data }: { data: RestaurantSignalsData | null }) {
    if (!data || !data.enabled || data.suggestions.length === 0) return null;
    const top = data.suggestions.slice(0, 3);

    return (
        <Card className="mb-3">
            <CardHeader className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h5 className="card-title mb-1">O que publicar esta semana</h5>
                    <p className="text-muted fs-13 mb-0">As sugestões principais, a partir das vendas e das reservas.</p>
                </div>
                <Link to="/marketing/o-que-publicar" className="btn btn-sm btn-outline-primary">Ver todas ({data.suggestions.length})</Link>
            </CardHeader>
            <CardBody>
                <ul className="list-unstyled mb-0 vstack gap-3">
                    {top.map((s) => (
                        <li key={s.key}>
                            <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span className="fw-semibold text-body">{s.title}</span>
                                <ConfidenceBadge value={s.confidence} />
                            </div>
                            <div className="fs-13 text-body">{s.sentence}</div>
                            {s.suggested_date && <div className="text-muted fs-12">Publicar: {fmtWeekday(s.suggested_date)}, {fmtDay(s.suggested_date)}</div>}
                        </li>
                    ))}
                </ul>
            </CardBody>
        </Card>
    );
}
