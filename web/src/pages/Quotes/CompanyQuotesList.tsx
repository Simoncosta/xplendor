import React, { useCallback, useEffect, useState } from "react";
import { Container, Badge, Spinner } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import ActionsMenu from "Components/Common/ActionsMenu";
import { ToastContainer, toast } from "react-toastify";
import { getCompanyQuotes, decideCompanyQuote, companyQuotePdfPath } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";
import { openPdfGet } from "helpers/download_helper";
import { IQuote, QUOTE_STATUS_META, VAT_NOTE, formatQuoteEuro, longDate } from "common/models/quote.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

const fmtDate = (iso?: string) =>
    iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "numeric", month: "short", year: "numeric" }) : "—";

/**
 * XPLENDOR — Orçamentos da XPLENDOR (LADO DA EMPRESA). A empresa vê os orçamentos
 * que lhe foram enviados (ligados a ela), abre o PDF e aceita ou recusa os que
 * estão em aberto. Totais mensal e de valor único separados, sem IVA.
 */
const CompanyQuotesList = () => {
    document.title = "Orçamentos | Xplendor";

    const companyId = useWorkingCompanyId();

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

    const openPdf = async (q: IQuote) => { const r = await openPdfGet(companyQuotePdfPath(companyId, q.id)); if (!r.ok) toast.error("Não foi possível abrir o PDF."); };

    const cols = useDataColumns<IQuote>("equipa.orcamentos-xplendor", [
        { id: "number", header: "Número", value: (q) => q.display_number, cell: (q) => <span className="fw-semibold">{q.display_number}</span>, nowrap: true, hideable: false },
        { id: "title", header: "Orçamento", value: (q) => q.title || q.description, mobile: "title" },
        {
            id: "status", header: "Estado", value: (q) => QUOTE_STATUS_META[q.status].label,
            cell: (q) => <Badge color={QUOTE_STATUS_META[q.status].color} title={q.status === "expired" ? "A validade terminou sem decisão." : undefined}>{QUOTE_STATUS_META[q.status].label}</Badge>,
        },
        { id: "date", header: "Data", value: (q) => q.sent_at ?? q.created_at, cell: (q) => fmtDate(q.sent_at ?? q.created_at), nowrap: true },
        { id: "valid", header: "Válido até", value: (q) => q.valid_until ?? undefined, cell: (q) => (q.valid_until ? longDate(q.valid_until) : "—"), defaultVisible: false },
        { id: "monthly", header: "Mensal", value: (q) => q.total_monthly, cell: (q) => (q.total_monthly > 0 ? `${formatQuoteEuro(q.total_monthly)}/mês` : "—"), align: "end", nowrap: true },
        { id: "oneoff", header: "Valor único", value: (q) => q.total_one_off, cell: (q) => (q.total_one_off > 0 ? formatQuoteEuro(q.total_one_off) : "—"), align: "end", nowrap: true },
    ] as DTColumn<IQuote>[]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Orçamentos" breadcrumbs={[{ label: "Equipa" }]}
                    info="Orçamentos que a XPLENDOR lhe enviou. Aceite ou recuse os que estão em aberto." />

                <PageCard title="Os meus orçamentos" info={VAT_NOTE} loading={loading && quotes.length > 0}
                    status={!loading ? <>{quotes.length} orçamento{quotes.length === 1 ? "" : "s"}{quotes.some((q) => q.status === "sent") ? ` · ${quotes.filter((q) => q.status === "sent").length} em aberto` : ""}</> : undefined}
                    actions={cols.selector}>
                    <DataTable
                        columns={cols}
                        data={quotes}
                        rowKey={(q) => q.id}
                        loading={loading}
                        caption="Orçamentos da XPLENDOR"
                        empty={{ message: "Ainda não tem orçamentos." }}
                        rowActions={(q) => {
                            const busy = busyId === q.id;
                            return (
                                <>
                                    <button type="button" className="btn btn-outline-primary btn-sm" title="Ver PDF" aria-label={`Ver PDF: ${q.display_number}`} onClick={() => void openPdf(q)}>
                                        <i className="ri-file-pdf-line" />
                                    </button>
                                    {q.status === "sent" && (
                                        <>
                                            <button type="button" className="btn btn-success btn-sm" disabled={busy} onClick={() => decide(q, "approve")}>
                                                {busy ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aceitar</>}
                                            </button>
                                            <ActionsMenu size="sm" label={`Mais ações: ${q.display_number}`} disabled={busy} items={[
                                                { label: "Recusar", icon: "ri-close-line", onClick: () => void decide(q, "reject") },
                                            ]} />
                                        </>
                                    )}
                                </>
                            );
                        }}
                    />
                </PageCard>
            </Container>
        </div>
    );
};

export default CompanyQuotesList;
