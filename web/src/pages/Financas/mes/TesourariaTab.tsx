import { useState } from "react";
import { Alert, Button, Col, Row, Table } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import { FinanceMonth, PaymentState } from "common/models/financeMonth.model";
import { dm, dmy, eur } from "./format";

/**
 * XPLENDOR — Finanças › Mês › Tesouraria: por semana do mês (entradas, saídas e por pagar pelo
 * vencimento), a lista de pagamentos (vencidos no topo, depois pelo vencimento), os próximos 30 dias e
 * a reconciliação com a conta corrente.
 */
const STATE_CLS: Record<PaymentState, string> = {
    pago: "bg-success-subtle text-success", pago_no_ato: "bg-success-subtle text-success", pago_em_parte: "bg-warning-subtle text-warning",
    por_pagar: "bg-info-subtle text-info", vencido: "bg-danger-subtle text-danger", credito: "bg-secondary-subtle text-secondary",
    descontado: "bg-secondary-subtle text-secondary", fora_cc: "bg-light text-body",
};
const PAGE = 50;

export default function TesourariaTab({ data, onOpen }: { data: FinanceMonth; onOpen: (docheaderId: string) => void }) {
    const c = data.cash;
    const [shown, setShown] = useState(PAGE);
    const rec = c.reconciliation;

    return (
        <div data-testid="finance-cash">
            <Alert color="info" className="py-2 fs-13"><i className="ri-information-line me-1" />{c.entries_note}</Alert>
            <div className="table-responsive mb-3">
                <Table size="sm" className="fs-13 align-middle mb-0" data-testid="cash-weeks">
                    <caption className="visually-hidden">Tesouraria por semana do mês</caption>
                    <thead className="table-light text-muted">
                        <tr><th>Semana</th><th className="text-end">Entradas</th><th className="text-end">Saídas</th><th className="text-end">Por pagar (vencimento)</th></tr>
                    </thead>
                    <tbody>
                        {c.weeks.map((w) => (
                            <tr key={w.from}>
                                <td className="text-nowrap">{dm(w.from)} a {dm(w.to)}</td>
                                <td className="text-end text-nowrap">{eur(w.in_cents)}</td>
                                <td className="text-end text-nowrap">{eur(w.out_cents)}</td>
                                <td className="text-end text-nowrap">{eur(w.due_cents)}</td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </div>
            {c.overdue_before_month_cents !== 0 && (
                <p className="fs-13 text-danger mb-3">Vencido antes deste mês e ainda por pagar: {eur(c.overdue_before_month_cents)}.</p>
            )}

            <Row className="g-3 mb-3">
                <Col lg={6}>
                    <div className="p-3 border rounded h-100" data-testid="cash-next">
                        <div className="text-muted fs-12 text-uppercase fw-semibold mb-2">Próximos 30 dias</div>
                        {c.next_30_days.map((w) => (
                            <div key={w.from} className="d-flex justify-content-between fs-13"><span>{dm(w.from)} a {dm(w.to)}</span><span className="fw-semibold">{eur(w.cents)}</span></div>
                        ))}
                        <div className="d-flex justify-content-between fs-13 text-danger mt-1"><span>Já vencido</span><span className="fw-semibold">{eur(c.overdue_cents)}</span></div>
                    </div>
                </Col>
                <Col lg={6}>
                    <div className={`p-3 border rounded h-100 ${rec.ok ? "" : "border-danger"}`} data-testid="cash-reconciliation">
                        <div className="text-muted fs-12 text-uppercase fw-semibold d-flex align-items-center gap-1 mb-2">Conferência com a conta corrente<InfoTip label="Conferência com a conta corrente" text={rec.note} /></div>
                        <div className="d-flex justify-content-between fs-13"><span>Por pagar neste ecrã (todos os meses)</span><span className="fw-semibold">{eur(rec.screen_total_cents)}</span></div>
                        <div className="d-flex justify-content-between fs-13"><span>Saldo real da conta corrente</span><span className="fw-semibold">{eur(rec.cc_real_balance_cents)}</span></div>
                        <div className="fs-13 mt-1">{rec.ok
                            ? <span className="text-success"><i className="ri-checkbox-circle-line me-1" />Bate, fornecedor a fornecedor.</span>
                            : <span className="text-danger"><i className="ri-close-circle-line me-1" />Não bate em {rec.mismatches.length} fornecedores: {rec.mismatches.slice(0, 3).map((m) => m.supplier).join(", ")}.</span>}</div>
                        <div className="fs-12 text-muted mt-1">Saldo do PingWin: {eur(rec.pingwin_balance_cents)} (conta {eur(rec.auto_paid_difference_cents)} de faturas-recibo, já pagas no ato).</div>
                        {c.cc_synced_at && <div className="fs-12 text-muted">Conta corrente sincronizada a {dmy(c.cc_synced_at.slice(0, 10))}.</div>}
                    </div>
                </Col>
            </Row>

            <div className="d-flex align-items-center gap-1 fs-12 text-muted text-uppercase fw-semibold mb-1">Pagamentos<InfoTip label="Data de pagamento" text={c.paid_date_note} /></div>
            <div className="table-responsive">
                <Table size="sm" hover className="fs-13 align-middle mb-2" data-testid="cash-payments">
                    <caption className="visually-hidden">Pagamentos do mês</caption>
                    <thead className="table-light text-muted">
                        <tr><th>Fornecedor</th><th>Documento</th><th className="text-end">Valor</th><th>Vencimento</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        {c.payments.slice(0, shown).map((p) => (
                            <tr key={p.docheader_id} role="button" onClick={() => onOpen(p.docheader_id)}>
                                <td>{p.supplier ?? "—"}</td>
                                <td className="text-nowrap">{p.document}</td>
                                <td className="text-end text-nowrap">{eur(p.total_cents)}</td>
                                <td className="text-nowrap">{dmy(p.due_date)}</td>
                                <td><span className={`badge ${STATE_CLS[p.state]}`}>{p.label}</span></td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </div>
            {c.payments.length > shown && (
                <Button size="sm" color="light" onClick={() => setShown((n) => n + PAGE)}>Mostrar mais ({c.payments.length - shown} por mostrar)</Button>
            )}
        </div>
    );
}
