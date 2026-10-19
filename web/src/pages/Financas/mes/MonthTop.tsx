import { Alert, Card, CardBody, Col, Row } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import { FinanceMonth } from "common/models/financeMonth.model";
import { eur, pct, pp } from "./format";

/**
 * XPLENDOR — Finanças › Mês: o topo (a frase-resumo, os avisos dos dados, os quatro cartões e a linha
 * que explica a diferença entre gasto e pago). O mesmo no ecrã e no fecho do mês.
 */
function Kpi({ title, tip, children, testid }: { title: string; tip: string; children: React.ReactNode; testid: string }) {
    return (
        <Card className="h-100 mb-0" data-testid={testid}>
            <CardBody style={{ padding: "1.25rem" }}>
                <div className="text-muted fs-12 text-uppercase fw-semibold d-flex align-items-center gap-1 mb-2">{title}<InfoTip label={title} text={tip} /></div>
                {children}
            </CardBody>
        </Card>
    );
}

export default function MonthTop({ data, storeName }: { data: FinanceMonth; storeName: string | null }) {
    const c = data.cards;
    const up = c.result.prev_pct !== null && c.result.pct !== null ? c.result.pct >= c.result.prev_pct : null;

    return (
        <>
            <Card className="mb-3" data-testid="finance-sentence">
                <CardBody className="p-3">
                    <p className="fs-15 fw-medium mb-1">{data.sentence}</p>
                    <div className="fs-12 text-muted" data-testid="finance-explain"><i className="ri-information-line me-1" />{data.explain}</div>
                </CardBody>
            </Card>
            {data.warnings.length > 0 && (
                <Alert color="warning" className="py-2 fs-13" data-testid="finance-warnings">
                    <ul className="mb-0 ps-3">{data.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                </Alert>
            )}
            <Row className="g-3 mb-3" data-testid="finance-cards">
                <Col xs={12} sm={6} xl={3}>
                    <Kpi testid="card-sales" title="Vendas" tip={`${c.sales.source} ${c.sales.days} dos ${c.sales.days_in_month} dias com vendas.`}>
                        <div className="fs-3 fw-semibold lh-1 mb-2">{eur(storeName ? c.sales.store_cents : c.sales.cents)}</div>
                        <div className="fs-12 text-muted">{storeName ? <>{storeName} · empresa toda {eur(c.sales.cents)}</> : "Todos os restaurantes, sem IVA"}</div>
                    </Kpi>
                </Col>
                <Col xs={12} sm={6} xl={3}>
                    <Kpi testid="card-expenses" title="Gastos" tip={c.expenses.source}>
                        <div className="fs-3 fw-semibold lh-1 mb-2">{eur(c.expenses.cents)}</div>
                        <div className="fs-12 text-muted">Pela data da fatura, sem IVA</div>
                        {c.expenses.open_count > 0 && <div className="fs-12 text-warning" data-testid="card-expenses-open">Em aberto: {c.expenses.open_count} {c.expenses.open_count === 1 ? "documento" : "documentos"} ({eur(c.expenses.open_cents)})</div>}
                        {c.expenses.unclassified_cents !== 0 && <div className="fs-12 text-danger">Por classificar: {eur(c.expenses.unclassified_cents)}</div>}
                    </Kpi>
                </Col>
                <Col xs={12} sm={6} xl={3}>
                    <Kpi testid="card-result" title="Resultado" tip={c.result.source}>
                        <div className={`fs-3 fw-semibold lh-1 mb-2 ${c.result.cents < 0 ? "text-danger" : "text-success"}`}>{eur(c.result.cents)}</div>
                        <div className="fs-12 text-muted">{pct(c.result.pct)} das vendas</div>
                        <div className="fs-12" data-testid="card-result-prev">
                            {up !== null && <i className={`${up ? "ri-arrow-up-line text-success" : "ri-arrow-down-line text-danger"} me-1`} aria-hidden />}
                            Mês anterior: {eur(c.result.prev_cents)} ({pct(c.result.prev_pct)}, {pp(c.result.pct, c.result.prev_pct)})
                        </div>
                    </Kpi>
                </Col>
                <Col xs={12} sm={6} xl={3}>
                    <Kpi testid="card-cash" title="Dinheiro" tip={c.cash.source}>
                        <div className="d-flex justify-content-between fs-13"><span>Pago no mês</span><span className="fw-semibold">{eur(c.cash.paid_cents)}</span></div>
                        <div className="d-flex justify-content-between fs-13"><span>Por pagar até ao fim do mês</span><span className="fw-semibold">{eur(c.cash.due_by_month_end_cents)}</span></div>
                        <div className="d-flex justify-content-between fs-13 text-danger"><span>Vencido</span><span className="fw-semibold">{eur(c.cash.overdue_cents)}</span></div>
                    </Kpi>
                </Col>
            </Row>
        </>
    );
}
