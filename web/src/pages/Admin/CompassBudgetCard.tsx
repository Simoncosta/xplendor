import { Badge } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import type { CompassAiBudget } from "common/models/radar.model";
import { usd } from "./RadarBudgetCard";

/**
 * Bússola: as frases "O quê" da IA (bussola_jogadas) neste mês, com o limite por empresa. Esgotado, as
 * jogadas dessa empresa ficam com o modelo de frase (a frase de recurso) até ao mês seguinte.
 */
const MONTHS = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"];
const monthLabel = (ym: string) => {
    const [y, m] = ym.split("-").map(Number);
    return m ? `${MONTHS[m - 1]} de ${y}` : ym;
};

export default function CompassBudgetCard({ compass }: { compass: CompassAiBudget | undefined }) {
    if (!compass) return null;
    const reached = compass.companies.filter((c) => c.reached).length;
    return (
        <PageCard title="Frases da Bússola este mês" data-testid="compass-budget"
            info={`Limite de ${compass.monthly_limit} pedidos por mês por empresa (mês de Lisboa), em todos os ramos. Esgotado, as jogadas ficam com o modelo de frase até ao mês seguinte.`}
            status={reached > 0 ? <Badge color="warning-subtle" className="text-warning">{reached} {reached === 1 ? "empresa no limite" : "empresas no limite"}</Badge> : undefined}>
            <div className="p-3">
                <div className="d-flex flex-wrap justify-content-between gap-2 mb-3">
                    <span className="fw-semibold" data-testid="compass-budget-total">{compass.requests_month} {compass.requests_month === 1 ? "pedido" : "pedidos"} · {usd(compass.cost_month_usd)}</span>
                    <span className="text-muted fs-12">{monthLabel(compass.month)}</span>
                </div>
                <h6 className="text-uppercase text-muted fs-12 mb-2">Por empresa</h6>
                {compass.companies.length === 0 ? <p className="text-muted fs-13 mb-0">Nenhuma empresa pediu frases este mês.</p> : (
                    <ul className="list-unstyled mb-0 fs-13" data-testid="compass-budget-companies">
                        {compass.companies.map((c) => (
                            <li key={c.company_id} className="d-flex justify-content-between gap-2 py-1 border-bottom">
                                <span className="text-truncate">{c.company ?? `Empresa ${c.company_id}`}
                                    {c.reached && <Badge color="warning-subtle" className="text-warning fw-normal ms-1">limite atingido</Badge>}</span>
                                <span className="flex-shrink-0">{c.requests} de {compass.monthly_limit} · {usd(c.cost_usd)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PageCard>
    );
}
