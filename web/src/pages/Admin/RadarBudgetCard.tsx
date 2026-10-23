import { Badge } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import type { RadarAdminBudget } from "common/models/radar.model";

/**
 * Bússola 1: o gasto de hoje (dia de Lisboa) da IA do Radar, com os tetos. Ao bater no teto global,
 * a relevância fica para o dia seguinte e o "porque lhe interessa" usa a frase de recurso; no teto
 * de uma empresa, só os sinais dessa empresa.
 */

const FUNCTION_LABELS: Record<string, string> = { radar_relevancia: "Relevância por ramo", radar_porque: "Porque lhe interessa" };
export const usd = (v: number) => `${v.toLocaleString("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 4 })} USD`;

export default function RadarBudgetCard({ budget }: { budget: RadarAdminBudget | undefined }) {
    if (!budget) return null;
    const pct = budget.global_cap_usd > 0 ? Math.min(100, Math.round((budget.spent_today_usd / budget.global_cap_usd) * 100)) : 0;
    return (
        <PageCard title="Custo da IA hoje" data-testid="radar-budget"
            info={`Teto de ${usd(budget.global_cap_usd)} por dia no global e de ${usd(budget.company_cap_usd)} por empresa (dia de Lisboa). Ao bater no teto, a relevância fica para o dia seguinte e o "porque lhe interessa" usa a frase de recurso; o root recebe um aviso no sino, uma vez por dia.`}
            status={budget.global_reached ? <Badge color="danger-subtle" className="text-danger">Teto atingido</Badge> : undefined}>
            <div className="p-3">
                <div className="d-flex flex-wrap justify-content-between gap-2 mb-1">
                    <span className="fw-semibold" data-testid="radar-budget-spent">{usd(budget.spent_today_usd)} de {usd(budget.global_cap_usd)}</span>
                    <span className="text-muted fs-12">{pct}% do teto global</span>
                </div>
                <div className="progress mb-3" style={{ height: 6 }} role="progressbar" aria-label="Gasto do dia face ao teto global" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
                    <div className={`progress-bar bg-${budget.global_reached ? "danger" : pct >= 80 ? "warning" : "primary"}`} style={{ width: `${pct}%` }} />
                </div>
                <ul className="list-unstyled mb-3 fs-13">
                    {budget.by_function.map((f) => (
                        <li key={f.function} className="d-flex justify-content-between gap-2 py-1 border-bottom">
                            <span>{FUNCTION_LABELS[f.function] ?? f.function} <span className="text-muted">· {f.requests} {f.requests === 1 ? "pedido" : "pedidos"}</span></span>
                            <span>{usd(f.cost_usd)}</span>
                        </li>
                    ))}
                </ul>
                <h6 className="text-uppercase text-muted fs-12 mb-2">Por empresa ("porque lhe interessa")</h6>
                {budget.companies.length === 0 ? <p className="text-muted fs-13 mb-0">Nenhuma empresa gastou hoje.</p> : (
                    <ul className="list-unstyled mb-0 fs-13" data-testid="radar-budget-companies">
                        {budget.companies.map((c) => (
                            <li key={c.company_id} className="d-flex justify-content-between gap-2 py-1 border-bottom">
                                <span className="text-truncate">{c.company ?? `Empresa ${c.company_id}`}
                                    {c.reached && <Badge color="warning-subtle" className="text-warning fw-normal ms-1">teto atingido</Badge>}</span>
                                <span className="flex-shrink-0">{usd(c.cost_usd)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PageCard>
    );
}
