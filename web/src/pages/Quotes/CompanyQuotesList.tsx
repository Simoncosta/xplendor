import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, CardHeader, Col, Container, Row, Badge, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { getCompanyQuotes, decideCompanyQuote, companyQuotePdfPath } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";
import { openPdfGet } from "helpers/download_helper";
import { IQuote, QUOTE_STATUS_META, VAT_NOTE, formatQuoteEuro, longDate } from "common/models/quote.model";

const fmtDate = (iso?: string) =>
    iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "numeric", month: "short", year: "numeric" }) : "—";

/**
 * XPLENDOR — Orçamentos da XPLENDOR (LADO DA EMPRESA). A empresa vê os orçamentos
 * que lhe foram enviados (ligados a ela), abre o PDF e aceita ou recusa os que
 * estão em aberto. Totais mensal e de valor único separados, sem IVA.
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
                ? { title: `Aceitar o orçamento ${q.display_number}?`, text: `${q.title || q.description}. ${VAT_NOTE}`, confirmText: "Aceitar", icon: "question", confirmVariant: "success" }
                : { title: `Recusar o orçamento ${q.display_number}?`, text: "O orçamento fica marcado como recusado.", confirmText: "Recusar", icon: "warning", confirmVariant: "danger" }
        );
        if (!ok) return;

        setBusyId(q.id);
        try {
            const r: any = await decideCompanyQuote(companyId, q.id, decision);
            setQuotes((prev) => prev.map((x) => (x.id === q.id ? (r?.data ?? x) : x)));
            toast.success(decision === "approve" ? "Orçamento aceite." : "Orçamento recusado.");
        } catch (err: any) {
            toast.error(err?.message || "Não foi possível registar a decisão.");
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
                        <p className="text-muted mb-0">Orçamentos que a XPLENDOR lhe enviou. Aceite ou recuse os que estão em aberto.</p>
                    </Col>
                </Row>

                <Card>
                    <CardHeader><h5 className="mb-0">Os meus orçamentos</h5></CardHeader>
                    <CardBody>
                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : quotes.length === 0 ? (
                            <p className="text-muted mb-0">Ainda não tem orçamentos.</p>
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
                                                        <span className="fw-semibold">{q.display_number}</span>
                                                        <span>{q.title || q.description}</span>
                                                        <Badge color={sm.color}>{sm.label}</Badge>
                                                    </div>
                                                    <small className="text-muted">
                                                        {fmtDate(q.sent_at ?? q.created_at)}{q.valid_until ? ` · válido até ${longDate(q.valid_until)}` : ""} · {VAT_NOTE}
                                                    </small>
                                                </div>
                                                <div className="text-end flex-shrink-0">
                                                    {q.total_monthly > 0 && <div className="fs-16 fw-semibold">{formatQuoteEuro(q.total_monthly)}/mês</div>}
                                                    {q.total_one_off > 0 && <div className={q.total_monthly > 0 ? "text-muted" : "fs-16 fw-semibold"}>{formatQuoteEuro(q.total_one_off)} valor único</div>}
                                                    <button type="button" className="btn btn-link btn-sm p-0" onClick={async () => { const r = await openPdfGet(companyQuotePdfPath(companyId, q.id)); if (!r.ok) toast.error("Não foi possível abrir o PDF."); }}>
                                                        <i className="ri-file-pdf-line me-1" />Ver PDF
                                                    </button>
                                                </div>
                                            </div>

                                            {q.status === "sent" ? (
                                                <div className="d-flex gap-2 mt-2">
                                                    <button type="button" className="btn btn-success btn-sm" disabled={busy} onClick={() => decide(q, "approve")}>
                                                        {busy ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aceitar</>}
                                                    </button>
                                                    <button type="button" className="btn btn-outline-danger btn-sm" disabled={busy} onClick={() => decide(q, "reject")}>
                                                        <i className="ri-close-line me-1" />Recusar
                                                    </button>
                                                </div>
                                            ) : (
                                                <div className="text-muted fs-13 mt-2">
                                                    {q.status === "accepted" && "Aceite."}
                                                    {q.status === "refused" && "Recusado."}
                                                    {q.status === "expired" && "Expirado: a validade terminou sem decisão."}
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
