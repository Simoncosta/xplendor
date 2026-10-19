import { useState } from "react";
import { Alert, Button, Card, CardBody, Col, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { excludeHabitual, restoreHabitual } from "helpers/laravel_helper";
import InfoTip from "Components/Common/InfoTip";
import { FinanceMonth } from "common/models/financeMonth.model";
import { dmy, eur, pct, pp } from "./format";

/**
 * XPLENDOR — Finanças › Mês: o topo (a frase-resumo, os avisos dos dados, os quatro cartões e a linha
 * que explica a diferença entre gasto e pago). O mesmo no ecrã e no fecho do mês. Enquanto faltarem
 * documentos habituais, o mês é provisório: o resultado estimado aparece ao lado do real.
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

type Missing = NonNullable<FinanceMonth["provisional"]>["missing"][number];

export default function MonthTop({ data, storeName, printable = false, companyId, canEdit = false, onChanged }: {
    data: FinanceMonth; storeName: string | null; printable?: boolean; companyId?: number | null; canEdit?: boolean; onChanged?: () => void;
}) {
    const c = data.cards;
    const [showMissing, setShowMissing] = useState(printable);
    const [asking, setAsking] = useState<{ m: Missing; scope: "mes" | "sempre" } | null>(null);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);
    const prov = data.provisional;
    const excluded = data.habitual_excluded ?? [];
    const editable = !printable && canEdit && !!companyId;

    // "Não vem este mês" / "Não é habitual": com confirmação; o resultado estimado recalcula logo.
    const confirm = async () => {
        if (!asking || !companyId) return;
        setBusy(true);
        try {
            const r: any = await excludeHabitual(companyId, { entity_pingwin_id: asking.m.entity_pingwin_id, scope: asking.scope, month: data.month,
                avg_cents: asking.m.avg_cents, reason: reason.trim() || null });
            toast.success(r?.message ?? "Recalculado.");
            setAsking(null);
            setReason("");
            onChanged?.();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        } finally {
            setBusy(false);
        }
    };
    const restore = async (id: number) => {
        if (!companyId) return;
        try {
            const r: any = await restoreHabitual(companyId, id);
            toast.success(r?.message ?? "Reposto.");
            onChanged?.();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível repor.");
        }
    };
    const up = c.result.prev_pct !== null && c.result.pct !== null ? c.result.pct >= c.result.prev_pct : null;

    return (
        <>
            <Card className="mb-3" data-testid="finance-sentence">
                <CardBody className="p-3">
                    <p className="fs-15 fw-medium mb-1">{data.sentence}</p>
                    <div className="fs-12 text-muted" data-testid="finance-explain"><i className="ri-information-line me-1" />{data.explain}</div>
                </CardBody>
            </Card>
            {prov && (
                <Alert color="warning" className="py-2 fs-13" data-testid="finance-provisional">
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <strong><i className="ri-time-line me-1" />{prov.text}</strong>
                        {!printable && <Button size="sm" color="link" className="p-0" onClick={() => setShowMissing((v) => !v)}>
                            {showMissing ? "Esconder a lista" : "Documentos habituais em falta"}
                        </Button>}
                    </div>
                    {showMissing && (
                        <ul className="mb-0 mt-1 ps-3" data-testid="finance-missing">
                            {prov.missing.map((m) => (
                                <li key={m.entity_pingwin_id} className="mb-1" data-testid={`missing-${m.entity_pingwin_id}`}>
                                    {m.supplier ?? "—"}: cerca de {eur(m.avg_cents)} <span className="text-muted">(em {m.months} dos 3 meses anteriores; o último foi {m.last_document ?? "—"} de {dmy(m.last_date)})</span>
                                    {editable && <span className="ms-2 text-nowrap">
                                        <Button size="sm" color="link" className="p-0 me-2 fs-12" onClick={() => setAsking({ m, scope: "mes" })}>Não vem este mês</Button>
                                        <Button size="sm" color="link" className="p-0 fs-12" onClick={() => setAsking({ m, scope: "sempre" })}>Não é habitual</Button>
                                    </span>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Alert>
            )}
            {excluded.length > 0 && !printable && (
                <div className="fs-12 text-muted mb-2" data-testid="finance-excluded">
                    Fora dos habituais neste mês: {excluded.map((e, i) => (
                        <span key={e.id}>{i > 0 && " · "}{e.supplier} ({e.scope === "mes" ? "não vem este mês" : "não é habitual"}{e.by ? `, por ${e.by}` : ""})
                            {editable && <Button size="sm" color="link" className="p-0 ms-1 fs-12 align-baseline" onClick={() => restore(e.id)}>Repor</Button>}</span>
                    ))}
                </div>
            )}
            <Modal isOpen={!!asking} toggle={() => !busy && setAsking(null)} centered data-testid="habitual-modal">
                <ModalHeader toggle={() => !busy && setAsking(null)}>{asking?.scope === "mes" ? "Não vem este mês?" : "Deixa de ser habitual?"}</ModalHeader>
                <ModalBody>
                    <p className="fs-13">{asking?.scope === "mes"
                        ? <><strong>{asking?.m.supplier}</strong> sai da lista só em {data.month_label}. No mês seguinte volta a contar, se faltar.</>
                        : <><strong>{asking?.m.supplier}</strong> deixa de entrar nos documentos habituais a partir de {data.month_label}. Pode repor em Finanças › Configuração.</>}
                        {" "}O resultado estimado deixa de contar com cerca de {eur(asking?.m.avg_cents ?? 0)}.</p>
                    <Label className="form-label mb-1" for="habitual-reason">Motivo (opcional)</Label>
                    <Input id="habitual-reason" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Por exemplo, o contrato terminou" />
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setAsking(null)} disabled={busy}>Cancelar</Button>
                    <Button color="primary" onClick={confirm} disabled={busy} data-testid="habitual-confirm">{busy ? <Spinner size="sm" /> : asking?.scope === "mes" ? "Não vem este mês" : "Não é habitual"}</Button>
                </ModalFooter>
            </Modal>
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
                        {c.result.estimated_cents !== null && c.result.estimated_cents !== undefined ? (
                            <>
                                <div className={`fs-3 fw-semibold lh-1 mb-1 ${c.result.estimated_cents < 0 ? "text-danger" : "text-success"}`} data-testid="card-result-estimated">
                                    {eur(c.result.estimated_cents)} <span className="badge bg-warning-subtle text-warning fs-11 align-middle">estimado</span>
                                </div>
                                <div className="fs-12 text-muted">{pct(c.result.estimated_pct)} das vendas · real até agora {eur(c.result.cents)} ({pct(c.result.pct)})</div>
                            </>
                        ) : (
                            <>
                                <div className={`fs-3 fw-semibold lh-1 mb-2 ${c.result.cents < 0 ? "text-danger" : "text-success"}`}>{eur(c.result.cents)}</div>
                                <div className="fs-12 text-muted">{pct(c.result.pct)} das vendas</div>
                            </>
                        )}
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
