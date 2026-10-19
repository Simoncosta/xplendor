import { useMemo, useState } from "react";
import { Alert, Button, Col, Input, Label, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import { classifyFinanceDocument } from "helpers/laravel_helper";
import { FinanceDocumentRow, FinanceGroup, FinanceMonth } from "common/models/financeMonth.model";
import CloseDocumentsModal, { CloseDoc } from "pages/Restauracao/faturas/CloseDocumentsModal";
import { dmy, eur } from "./format";

/**
 * XPLENDOR — Finanças › Mês › Documentos: todos os documentos do mês (data, fornecedor, tipo, número,
 * família, sem IVA, IVA, total, estado e vencimento), com filtros e CSV. Os "Por classificar" no topo,
 * com a classificação rápida (este documento, ou sempre para o fornecedor). Os em Aberto destacados,
 * com "Fechar no PingWin" (o da lista de documentos: este ecrã só o chama).
 */
type Props = { companyId: number; data: FinanceMonth; onOpen: (docheaderId: string) => void; onChanged: () => void };

const csvCell = (v: any) => {
    const s = v === null || v === undefined ? "" : String(v);
    return /[";\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};
const PAGE = 50;
const num = (c: number | null) => (c === null ? "" : (c / 100).toFixed(2).replace(".", ","));

export function documentsCsv(rows: FinanceDocumentRow[]): string {
    const head = ["Data", "Fornecedor", "Tipo", "Número", "Família", "Grupo", "Sem IVA", "IVA", "Total", "Estado no PingWin", "Pagamento", "Vencimento"];
    const lines = rows.map((r) => [r.doc_date, r.supplier, r.doctype, r.document, r.family, r.group_label, num(r.net_cents), num(r.vat_cents), num(r.total_cents),
        r.docstatus, r.payment.label, r.due_date].map(csvCell).join(";"));
    return [head.join(";"), ...lines].join("\n");
}

export default function DocumentosTab({ companyId, data, onOpen, onChanged }: Props) {
    const [group, setGroup] = useState<string>("");
    const [state, setState] = useState<string>("");
    const [supplier, setSupplier] = useState<string>("");
    const [busy, setBusy] = useState<string | null>(null);
    const [choice, setChoice] = useState<Record<string, FinanceGroup>>({});
    const [selected, setSelected] = useState<string[]>([]);
    const [closing, setClosing] = useState<CloseDoc[] | null>(null);
    const [limit, setLimit] = useState(PAGE);
    const rows = data.documents.rows;
    const groups = data.groups.filter((g) => g.key !== "por_classificar");

    const suppliers = useMemo(() => Array.from(new Set(rows.map((r) => r.supplier ?? "—"))).sort(), [rows]);
    const shown = rows.filter((r) => (!group || (group === "por_classificar" ? r.unclassified_cents !== 0 : r.group === group))
        && (!state || (state === "aberto" ? r.open : r.payment.state === state))
        && (!supplier || (r.supplier ?? "—") === supplier));
    const unclassified = data.documents.unclassified;
    const open = rows.filter((r) => r.open);
    const closable = (r: FinanceDocumentRow) => r.open && r.docstatus_id === "8001" && (r.close_problems ?? []).length === 0;

    const classify = async (r: FinanceDocumentRow, scope: "documento" | "fornecedor") => {
        const g = choice[r.docheader_id];
        if (!g) return;
        setBusy(`${scope}-${r.docheader_id}`);
        try {
            const res: any = await classifyFinanceDocument(companyId, { scope, docheader_id: r.docheader_id, group: g });
            toast.success(res?.message ?? "Classificado.");
            onChanged();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível classificar.");
        } finally {
            setBusy(null);
        }
    };

    const exportCsv = () => {
        const blob = new Blob(["﻿" + documentsCsv(shown)], { type: "text/csv;charset=utf-8" });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = `documentos-${data.month}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    };
    const toDoc = (r: FinanceDocumentRow): CloseDoc => ({ docheader_id: r.docheader_id, document: r.document, supplier: r.supplier, total_cents: r.total_cents });
    const closeReason = (r: FinanceDocumentRow) => !data.can.close ? "Só quem pode lançar faturas fecha documentos no PingWin."
        : r.docstatus_id !== "8001" ? "Em edição no PingWin: termine o documento no PingWin." : (r.close_problems ?? []).map((p) => p.message).join(" ") || null;

    return (
        <div data-testid="finance-documents">
            {unclassified.length > 0 && (
                <div className="mb-3" data-testid="docs-unclassified">
                    <div className="fs-12 text-danger text-uppercase fw-semibold mb-1">Por classificar ({unclassified.length})</div>
                    <ul className="list-unstyled mb-0">
                        {unclassified.map((r) => (
                            <li key={r.docheader_id} className="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom fs-13">
                                <span className="flex-grow-1">
                                    <button type="button" className="btn btn-link p-0 fs-13 align-baseline" onClick={() => onOpen(r.docheader_id)}>{r.document}</button>
                                    {" "}· {r.supplier} · {eur(r.unclassified_cents)}
                                    <div className="fs-12 text-muted">{r.unclassified_reason}</div>
                                </span>
                                {data.can.classify && <>
                                    <div style={{ width: 230 }}><XSelect<string> small ariaLabel={`Grupo de ${r.document}`} placeholder="Escolher o grupo…" searchable={false}
                                        value={choice[r.docheader_id] ?? ""} options={groups.map((g) => ({ value: g.key, label: g.label }))}
                                        onChange={(v) => setChoice((s) => ({ ...s, [r.docheader_id]: v as FinanceGroup }))} /></div>
                                    <ReasonButton size="sm" color="outline-primary" reason={choice[r.docheader_id] ? null : "Escolha o grupo."} disabled={!!busy} onClick={() => classify(r, "documento")}>
                                        {busy === `documento-${r.docheader_id}` ? <Spinner size="sm" /> : "Este documento"}
                                    </ReasonButton>
                                    <ReasonButton size="sm" color="outline-secondary" reason={choice[r.docheader_id] ? null : "Escolha o grupo."} disabled={!!busy} onClick={() => classify(r, "fornecedor")}>
                                        {busy === `fornecedor-${r.docheader_id}` ? <Spinner size="sm" /> : "Sempre para este fornecedor"}
                                    </ReasonButton>
                                </>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {open.length > 0 && (
                <Alert color="warning" className="py-2 fs-13 d-flex flex-wrap align-items-center justify-content-between gap-2" data-testid="docs-open">
                    <span><i className="ri-error-warning-line me-1" />Em aberto: {data.documents.open.count} {data.documents.open.count === 1 ? "documento" : "documentos"} ({eur(data.documents.open.total_cents)}) ainda não estão fechados no PingWin; não contam no stock.</span>
                    {selected.length > 0 && (
                        <Button size="sm" color="warning" onClick={() => setClosing(rows.filter((r) => selected.includes(r.docheader_id)).map(toDoc))} data-testid="close-selected">
                            Fechar os selecionados ({selected.length})
                        </Button>
                    )}
                </Alert>
            )}

            <Row className="g-2 align-items-end mb-2">
                <Col xs={12} sm={4} lg={3}>
                    <Label className="form-label mb-1" for="fd-group">Família</Label>
                    <XSelect<string> id="fd-group" ariaLabel="Família" value={group} searchable={false}
                        options={[{ value: "", label: "Todas" }, ...data.groups.map((g) => ({ value: g.key, label: g.label })), { value: "por_classificar", label: "Por classificar" }]} onChange={setGroup} />
                </Col>
                <Col xs={12} sm={4} lg={3}>
                    <Label className="form-label mb-1" for="fd-state">Estado</Label>
                    <XSelect<string> id="fd-state" ariaLabel="Estado" value={state} searchable={false}
                        options={[{ value: "", label: "Todos" }, { value: "aberto", label: "Em aberto" }, { value: "por_pagar", label: "Por pagar" }, { value: "vencido", label: "Vencido" },
                            { value: "pago", label: "Pago" }, { value: "pago_no_ato", label: "Pago no ato" }]} onChange={setState} />
                </Col>
                <Col xs={12} sm={4} lg={3}>
                    <Label className="form-label mb-1" for="fd-supplier">Fornecedor</Label>
                    <XSelect<string> id="fd-supplier" ariaLabel="Fornecedor" value={supplier} options={[{ value: "", label: "Todos" }, ...suppliers.map((s) => ({ value: s, label: s }))]} onChange={setSupplier} />
                </Col>
                <Col xs={12} lg={3} className="text-lg-end">
                    <Button size="sm" color="light" onClick={exportCsv} data-testid="docs-csv"><i className="ri-download-2-line me-1" />Exportar CSV</Button>
                </Col>
            </Row>

            <div className="table-responsive">
                <Table size="sm" hover className="fs-13 align-middle mb-0" data-testid="docs-table">
                    <caption className="visually-hidden">Documentos do mês</caption>
                    <thead className="table-light text-muted">
                        <tr><th style={{ width: 28 }}><span className="visually-hidden">Escolher</span></th><th>Data</th><th>Fornecedor</th><th>Tipo</th><th>Número</th><th>Família</th>
                            <th className="text-end">Sem IVA</th><th className="text-end">IVA</th><th className="text-end">Total</th><th>Estado</th><th>Vencimento</th><th /></tr>
                    </thead>
                    <tbody>
                        {shown.slice(0, limit).map((r) => (
                            <tr key={r.docheader_id} className={r.open ? "table-warning" : r.unclassified_cents !== 0 ? "table-danger" : undefined} data-testid={`doc-${r.docheader_id}`}>
                                <td>{r.open && <Input type="checkbox" className="form-check-input" aria-label={`Escolher ${r.document}`} disabled={!closable(r)}
                                    checked={selected.includes(r.docheader_id)} onChange={() => setSelected((s) => (s.includes(r.docheader_id) ? s.filter((x) => x !== r.docheader_id) : [...s, r.docheader_id].slice(0, 10)))} />}</td>
                                <td className="text-nowrap">{dmy(r.doc_date)}</td>
                                <td>{r.supplier}</td>
                                <td className="text-muted">{r.doctype}</td>
                                <td className="text-nowrap"><button type="button" className="btn btn-link p-0 fs-13" onClick={() => onOpen(r.docheader_id)}>{r.document}</button></td>
                                <td>{r.family ?? r.group_label}{r.families.length > 1 && <span className="text-muted"> e mais {r.families.length - 1}</span>}{r.out_of_result && <span className="badge bg-secondary-subtle text-secondary ms-1">fora do resultado</span>}</td>
                                <td className="text-end text-nowrap">{eur(r.net_cents)}</td>
                                <td className="text-end text-nowrap">{eur(r.vat_cents)}</td>
                                <td className="text-end text-nowrap">{eur(r.total_cents)}</td>
                                <td>{r.open ? <span className="badge bg-warning-subtle text-warning">{r.docstatus}</span> : r.payment.label}</td>
                                <td className="text-nowrap">{dmy(r.due_date)}</td>
                                <td className="text-nowrap">{r.open && (
                                    <ReasonButton size="sm" color="outline-warning" reason={closeReason(r)} onClick={() => setClosing([toDoc(r)])}>Fechar no PingWin</ReasonButton>
                                )}</td>
                            </tr>
                        ))}
                        {shown.length === 0 && <tr><td colSpan={12} className="text-center text-muted py-3">Sem documentos com estes filtros.</td></tr>}
                    </tbody>
                </Table>
            </div>
            {shown.length > limit && (
                <Button size="sm" color="light" className="mt-2" onClick={() => setLimit((n) => n + PAGE)}>Mostrar mais ({shown.length - limit} por mostrar)</Button>
            )}
            {closing && (
                <CloseDocumentsModal companyId={companyId} docs={closing} isOpen={!!closing} onClose={() => { setClosing(null); setSelected([]); onChanged(); }} onDone={onChanged} />
            )}
        </div>
    );
}
