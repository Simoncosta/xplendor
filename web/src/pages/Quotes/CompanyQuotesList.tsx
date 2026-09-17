import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, CardHeader, Col, Container, Row, Badge, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { getCompanyQuotes, decideCompanyQuote } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";
import { IQuote, QUOTE_STATUS_META, formatQuoteEuro } from "common/models/quote.model";

const fmtDate = (iso?: string) =>
    iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "numeric", month: "short", year: "numeric" }) : "—";

/**
 * XPLENDOR — "Os meus orçamentos" (LADO DO STAND). A empresa vê os orçamentos
 * que a XPLENDOR lhe enviou (ligados a ela) e aprova/rejeita os que estão em
 * validação. Marcar pago/concluído é do super-admin — aqui só a decisão.
 */
const CompanyQuotesList = () => {
    document.title = "Orçamentos | Xplendor";

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        return a ? Number(JSON.parse(a).company_id || 0) : 0;
    }, []);

    const [quotes, setQuotes] = useState<IQuote[]>([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState<number | null>(null);

    const load = useCallback(() => {
        if (!companyId) return;
        setLoading(true);
        getCompanyQuotes(companyId)
            .then((r: any) => setQuotes(r?.data ?? []))
            .catch(() => setQuotes([]))
            .finally(() => setLoading(false));
    }, [companyId]);

    useEffect(() => { load(); }, [load]);

    const decide = async (q: IQuote, decision: "approve" | "reject") => {
        const ok = await confirmAction(
            decision === "approve"
                ? { title: "Aprovar orçamento?", text: `${q.description} — ${formatQuoteEuro(q.amount)} (acresce IVA).`, confirmText: "Aprovar", icon: "question", confirmVariant: "success" }
                : { title: "Rejeitar orçamento?", text: "O orçamento fica marcado como rejeitado.", confirmText: "Rejeitar", icon: "warning", confirmVariant: "danger" }
        );
        if (!ok) return;

        setBusyId(q.id);
        try {
            const r: any = await decideCompanyQuote(companyId, q.id, decision);
            setQuotes((prev) => prev.map((x) => (x.id === q.id ? (r?.data ?? x) : x)));
            toast.success(decision === "approve" ? "Orçamento aprovado." : "Orçamento rejeitado.");
        } catch (err: any) {
            toast.error(err?.response?.data?.message || "Não foi possível registar a decisão.");
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3">
                    <Col>
                        <h4 className="mb-1"><i className="ri-file-list-3-line text-primary me-2" />Orçamentos</h4>
                        <p className="text-muted mb-0">Orçamentos que a XPLENDOR te enviou. Aprova ou rejeita os que estão em validação.</p>
                    </Col>
                </Row>

                <Card>
                    <CardHeader><h5 className="mb-0">Os meus orçamentos</h5></CardHeader>
                    <CardBody>
                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : quotes.length === 0 ? (
                            <p className="text-muted mb-0">Ainda não tens orçamentos.</p>
                        ) : (
                            <div className="d-flex flex-column gap-2">
                                {quotes.map((q) => {
                                    const sm = QUOTE_STATUS_META[q.status];
                                    const busy = busyId === q.id;
                                    return (
                                        <div key={q.id} className="border rounded p-3">
                                            <div className="d-flex align-items-start gap-3 flex-wrap">
                                                <div className="flex-grow-1 min-w-0">
                                                    <div className="d-flex align-items-center gap-2 flex-wrap mb-1">
                                                        <span className="fw-semibold">{q.description}</span>
                                                        <Badge color={sm.color}>{sm.label}</Badge>
                                                    </div>
                                                    <small className="text-muted">{fmtDate(q.created_at)} · acresce IVA à taxa legal</small>
                                                </div>
                                                <div className="text-end flex-shrink-0">
                                                    <div className="fs-16 fw-semibold">{formatQuoteEuro(q.amount)}</div>
                                                </div>
                                            </div>

                                            {q.status === "pending" ? (
                                                <div className="d-flex gap-2 mt-2">
                                                    <button type="button" className="btn btn-success btn-sm" disabled={busy} onClick={() => decide(q, "approve")}>
                                                        {busy ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aprovar</>}
                                                    </button>
                                                    <button type="button" className="btn btn-outline-danger btn-sm" disabled={busy} onClick={() => decide(q, "reject")}>
                                                        <i className="ri-close-line me-1" />Rejeitar
                                                    </button>
                                                </div>
                                            ) : (
                                                <div className="text-muted fs-13 mt-2">
                                                    {q.status === "approved" && "Aprovado — a aguardar faturação."}
                                                    {q.status === "rejected" && "Rejeitado."}
                                                    {q.status === "paid" && "Pago — em execução."}
                                                    {q.status === "completed" && "Concluído."}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>
        </div>
    );
};

export default CompanyQuotesList;
