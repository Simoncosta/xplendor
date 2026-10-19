import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Button, Col, Input, Label, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import { classifyFinanceDocument, downloadFinanceSheet, downloadFinanceZip, editFinanceDocument, getFinanceAccountingFiles, undoFinancePayment, unmarkFinanceSent } from "helpers/laravel_helper";
import { FinanceDocumentRow, FinanceGroup, FinanceMonth } from "common/models/financeMonth.model";
import CloseDocumentsModal, { CloseDoc } from "pages/Restauracao/faturas/CloseDocumentsModal";
import { AccountantEmailModal, DocRef, ImportSheetModal, MarkSentModal, RegisterPaymentModal } from "./FinanceModals";
import { dmy, eur } from "./format";

/**
 * XPLENDOR — Finanças › Mês › Documentos: todos os documentos do mês (data, fornecedor, tipo, número,
 * família, sem IVA, IVA, total, estado, vencimento, forma de pagamento, pago em, enviado à
 * contabilidade, forma de envio e nota), com filtros e CSV. Os "Por classificar" no topo, com a
 * classificação rápida e "Criar regra"; os em Aberto destacados, com "Fechar no PingWin". O envio à
 * contabilidade substitui a folha manual: marcar como enviado, a folha em XLSX, o ZIP, o email ao
 * contabilista e importar a folha antiga. Tudo só na XPLENDOR, menos o "Fechar no PingWin".
 */
type Props = {
    companyId: number; data: FinanceMonth; onOpen: (docheaderId: string) => void; onChanged: () => void;
    /** O filtro inicial (os contadores de Trabalho do mês abrem a lista já filtrada). */
    initialGroup?: string; initialState?: string;
    /**
     * all: tudo (o painel do Resumo); documentos: sem a barra da contabilidade (Trabalho do mês ›
     * Documentos); contabilidade: a barra da contabilidade primeiro, sem os por classificar (Trabalho do
     * mês › Envio à contabilidade).
     */
    view?: "all" | "documentos" | "contabilidade";
};

const csvCell = (v: any) => {
    const s = v === null || v === undefined ? "" : String(v);
    return /[";\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};
const PAGE = 50;
const num = (c: number | null) => (c === null ? "" : (c / 100).toFixed(2).replace(".", ","));
const OPEN_STATES = ["por_pagar", "vencido", "pago_em_parte", "fora_cc"];

export function documentsCsv(rows: FinanceDocumentRow[]): string {
    const head = ["Data", "Fornecedor", "Tipo", "Número", "Família", "Grupo", "Sem IVA", "IVA", "Total", "Estado no PingWin", "Pagamento", "Vencimento"];
    const lines = rows.map((r) => [r.doc_date, r.supplier, r.doctype, r.document, r.family, r.group_label, num(r.net_cents), num(r.vat_cents), num(r.total_cents),
        r.docstatus, r.payment.label, r.due_date].map(csvCell).join(";"));
    return [head.join(";"), ...lines].join("\n");
}

const save = (blob: Blob, name: string) => {
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    URL.revokeObjectURL(a.href);
};

/** A nota de um documento, editável na própria linha (grava ao sair do campo). */
function NoteCell({ companyId, row, canEdit, onSaved }: { companyId: number; row: FinanceDocumentRow; canEdit: boolean; onSaved: () => void }) {
    const [v, setV] = useState(row.accounting.note ?? "");
    if (!canEdit) return <span>{row.accounting.note ?? ""}</span>;
    const commit = async () => {
        if ((row.accounting.note ?? "") === v) return;
        try {
            await editFinanceDocument(companyId, row.docheader_id, { note: v });
            onSaved();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar a nota.");
        }
    };
    return <Input bsSize="sm" value={v} maxLength={500} aria-label={`Nota de ${row.document}`} onChange={(e) => setV(e.target.value)} onBlur={commit} style={{ minWidth: 140 }} />;
}

export default function DocumentosTab({ companyId, data, onOpen, onChanged, initialGroup = "", initialState = "", view = "all" }: Props) {
    const [group, setGroup] = useState<string>(initialGroup);
    const [state, setState] = useState<string>(initialState);
    const [supplier, setSupplier] = useState<string>("");
    const [busy, setBusy] = useState<string | null>(null);
    const [choice, setChoice] = useState<Record<string, FinanceGroup>>({});
    const [selected, setSelected] = useState<string[]>([]);
    const [closing, setClosing] = useState<CloseDoc[] | null>(null);
    const [paying, setPaying] = useState<DocRef[] | null>(null);
    const [marking, setMarking] = useState<string[] | null>(null);
    const [emailing, setEmailing] = useState<string[] | null>(null);
    const [importing, setImporting] = useState(false);
    const [limit, setLimit] = useState(PAGE);
    const rows = data.documents.rows;
    const groups = data.groups.filter((g) => g.key !== "por_classificar");
    const canEdit = data.can.classify;

    const suppliers = useMemo(() => Array.from(new Set(rows.map((r) => r.supplier ?? "—"))).sort(), [rows]);
    const shown = rows.filter((r) => (!group || (group === "por_classificar" ? r.unclassified_cents !== 0 : r.group === group))
        && (!state || (state === "aberto" ? r.open : state === "por_enviar" ? !r.accounting.sent : r.payment.state === state))
        && (!supplier || (r.supplier ?? "—") === supplier));
    const unclassified = data.documents.unclassified;
    const open = rows.filter((r) => r.open);
    const sel = rows.filter((r) => selected.includes(r.docheader_id));
    const closable = (r: FinanceDocumentRow) => r.open && r.docstatus_id === "8001" && (r.close_problems ?? []).length === 0;
    const payable = (r: FinanceDocumentRow) => OPEN_STATES.includes(r.payment.state) && !r.payment.payment && r.total_cents > 0;
    const toPay = (r: FinanceDocumentRow): DocRef => ({ docheader_id: r.docheader_id, document: r.document, supplier: r.supplier, open_cents: r.payment.open_cents || r.total_cents });
    const scope = () => ({ month: data.month, docheader_ids: selected.length ? selected : shown.map((r) => r.docheader_id) });

    const classify = async (r: FinanceDocumentRow, s: "documento" | "fornecedor") => {
        const g = choice[r.docheader_id];
        if (!g) return;
        setBusy(`${s}-${r.docheader_id}`);
        try {
            const res: any = await classifyFinanceDocument(companyId, { scope: s, docheader_id: r.docheader_id, group: g });
            toast.success(res?.message ?? "Classificado.");
            onChanged();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível classificar.");
        } finally {
            setBusy(null);
        }
    };
    const run = async (key: string, fn: () => Promise<any>, ok?: string) => {
        setBusy(key);
        try {
            const r: any = await fn();
            if (ok) toast.success(r?.message ?? ok);
            return r;
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível concluir.");
            return null;
        } finally {
            setBusy(null);
        }
    };
    const exportCsv = () => save(new Blob(["﻿" + documentsCsv(shown)], { type: "text/csv;charset=utf-8" }), `documentos-${data.month}.csv`);
    const exportSheet = () => run("sheet", async () => save(await downloadFinanceSheet(companyId, scope()) as any, `contabilidade-${data.month}.xlsx`));
    const prepareZip = () => run("zip", async () => {
        const f: any = await getFinanceAccountingFiles(companyId, scope());
        const missing = f?.data?.missing ?? [];
        save(await downloadFinanceZip(companyId, scope()) as any, `contabilidade-${data.month}.zip`);
        toast.info(missing.length ? `ZIP preparado. Sem ficheiro na XPLENDOR: ${missing.map((m: any) => m.document).join(", ")}.` : "ZIP preparado com todos os ficheiros.");
    });
    const toDoc = (r: FinanceDocumentRow): CloseDoc => ({ docheader_id: r.docheader_id, document: r.document, supplier: r.supplier, total_cents: r.total_cents });
    const closeReason = (r: FinanceDocumentRow) => !data.can.close ? "Só quem pode lançar faturas fecha documentos no PingWin."
        : r.docstatus_id !== "8001" ? "Em edição no PingWin: termine o documento no PingWin." : (r.close_problems ?? []).map((p) => p.message).join(" ") || null;
    const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const ruleLink = (r: FinanceDocumentRow) => r.new_type
        ? `/financas/configuracao/regras?tipo=tipo_documento&valor=${encodeURIComponent(r.new_type.docconfig_id)}&rotulo=${encodeURIComponent(r.new_type.label)}`
        : `/financas/configuracao/regras?tipo=entidade&valor=${encodeURIComponent(r.entity_pingwin_id ?? "")}&rotulo=${encodeURIComponent(r.supplier ?? "")}`;

    const accounting = (
        <div className="d-flex flex-wrap align-items-center gap-2 mb-2" data-testid="docs-accounting">
            <span className="fs-12 text-muted text-uppercase fw-semibold me-1">Contabilidade{selected.length ? ` (${selected.length} escolhidos)` : " (os documentos à vista)"}:</span>
            <Button size="sm" color="outline-secondary" onClick={exportSheet} disabled={busy === "sheet"} data-testid="export-sheet"><i className="ri-file-excel-2-line me-1" />Exportar folha da contabilidade</Button>
            <Button size="sm" color="outline-secondary" onClick={prepareZip} disabled={busy === "zip"} data-testid="prepare-zip"><i className="ri-folder-zip-line me-1" />Preparar envio</Button>
            <ReasonButton size="sm" color="outline-primary" reason={canEdit ? null : "Só quem edita as Finanças envia."} onClick={() => setEmailing(scope().docheader_ids)} data-testid="send-accountant">
                <i className="ri-mail-send-line me-1" />Enviar ao contabilista
            </ReasonButton>
            <ReasonButton size="sm" color="link" reason={canEdit ? null : "Só quem edita as Finanças importa."} onClick={() => setImporting(true)} data-testid="import-sheet">Importar folha antiga (XLSX)</ReasonButton>
        </div>
    );

    return (
        <div data-testid="finance-documents">
            {view === "contabilidade" && (
                <p className="fs-13 text-muted mb-2">A folha, o ZIP com as faturas e o email ao contabilista levam os documentos escolhidos na lista ou, sem nenhum escolhido, os que estão à vista com os filtros.</p>
            )}
            {view !== "contabilidade" && unclassified.length > 0 && (
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
                                {canEdit && <>
                                    <Link to={ruleLink(r)} className="btn btn-sm btn-outline-primary" data-testid={`create-rule-${r.docheader_id}`}><i className="ri-add-line me-1" />Criar regra</Link>
                                    {!r.new_type && <>
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
                                </>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {view !== "contabilidade" && open.length > 0 && (
                <Alert color="warning" className="py-2 fs-13" data-testid="docs-open">
                    <i className="ri-error-warning-line me-1" />Em aberto: {data.documents.open.count} {data.documents.open.count === 1 ? "documento" : "documentos"} ({eur(data.documents.open.total_cents)}) ainda não estão fechados no PingWin; não contam no stock.
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
                        options={[{ value: "", label: "Todos" }, { value: "por_enviar", label: "Por enviar à contabilidade" }, { value: "aberto", label: "Em aberto" },
                            { value: "por_pagar", label: "Por pagar" }, { value: "vencido", label: "Vencido" }, { value: "pago", label: "Pago" },
                            { value: "pago_xplendor", label: "Pago (registado na XPLENDOR)" }, { value: "pago_no_ato", label: "Pago no ato" }]} onChange={setState} />
                </Col>
                <Col xs={12} sm={4} lg={3}>
                    <Label className="form-label mb-1" for="fd-supplier">Fornecedor</Label>
                    <XSelect<string> id="fd-supplier" ariaLabel="Fornecedor" value={supplier} options={[{ value: "", label: "Todos" }, ...suppliers.map((s) => ({ value: s, label: s }))]} onChange={setSupplier} />
                </Col>
                <Col xs={12} lg={3} className="text-lg-end">
                    <Button size="sm" color="light" onClick={exportCsv} data-testid="docs-csv"><i className="ri-download-2-line me-1" />Exportar CSV</Button>
                </Col>
            </Row>
            {view !== "documentos" && accounting}

            {selected.length > 0 && (
                <div className="d-flex flex-wrap align-items-center gap-2 mb-2 p-2 border rounded" data-testid="docs-selection">
                    <span className="fs-13 fw-medium">{selected.length} escolhidos</span>
                    {canEdit && <Button size="sm" color="primary" onClick={() => setMarking(selected)} data-testid="mark-sent">Marcar como enviado</Button>}
                    {canEdit && <ReasonButton size="sm" color="outline-primary" reason={sel.every(payable) ? null : "Só documentos por pagar, sem pagamento registado."}
                        onClick={() => setPaying(sel.map(toPay))}>Registar pagamento</ReasonButton>}
                    <ReasonButton size="sm" color="outline-warning" reason={sel.length <= 10 && sel.every(closable) ? null : "Só documentos em Aberto que se podem fechar (no máximo 10)."}
                        onClick={() => setClosing(sel.map(toDoc))} data-testid="close-selected">Fechar os selecionados</ReasonButton>
                    <Button size="sm" color="link" onClick={() => setSelected([])}>Limpar</Button>
                </div>
            )}

            <div className="table-responsive">
                <Table size="sm" hover className="fs-13 align-middle mb-0" data-testid="docs-table">
                    <caption className="visually-hidden">Documentos do mês</caption>
                    <thead className="table-light text-muted">
                        <tr><th style={{ width: 28 }}><Input type="checkbox" aria-label="Escolher todos" checked={shown.length > 0 && shown.every((r) => selected.includes(r.docheader_id))}
                            onChange={(e) => setSelected(e.target.checked ? shown.map((r) => r.docheader_id) : [])} /></th>
                            <th>Data</th><th>Fornecedor</th><th>Tipo</th><th>Número</th><th>Família</th>
                            <th className="text-end">Sem IVA</th><th className="text-end">IVA</th><th className="text-end">Total</th><th>Estado</th><th>Vencimento</th>
                            <th>Forma de pagamento</th><th>Pago em</th><th>Enviado em</th><th>Forma de envio</th><th>Nota</th><th /></tr>
                    </thead>
                    <tbody>
                        {shown.slice(0, limit).map((r) => {
                            const reg = r.payment.payment && !r.payment.xplendor_superseded ? r.payment.payment : null;
                            const rule = r.parts.find((p) => p.rule);
                            return (
                                <tr key={r.docheader_id} className={r.open ? "table-warning" : r.unclassified_cents !== 0 ? "table-danger" : undefined} data-testid={`doc-${r.docheader_id}`}>
                                    <td><Input type="checkbox" aria-label={`Escolher ${r.document}`} checked={selected.includes(r.docheader_id)} onChange={() => toggle(r.docheader_id)} /></td>
                                    <td className="text-nowrap">{dmy(r.doc_date)}</td>
                                    <td>{r.supplier}</td>
                                    <td className="text-muted">{r.doctype}</td>
                                    <td className="text-nowrap"><button type="button" className="btn btn-link p-0 fs-13" onClick={() => onOpen(r.docheader_id)}>{r.document}</button></td>
                                    <td>
                                        {r.family ?? r.group_label}{r.families.length > 1 && <span className="text-muted"> e mais {r.families.length - 1}</span>}
                                        {r.out_of_result && <span className="badge bg-secondary-subtle text-secondary ms-1">fora do resultado</span>}
                                        {rule && <div className="fs-11 text-primary" data-testid={`rule-${r.docheader_id}`}>Regra: {rule.rule!.label}{rule.pingwin_family && <span className="text-muted"> · no PingWin: {rule.pingwin_family}</span>}</div>}
                                    </td>
                                    <td className="text-end text-nowrap">{eur(r.net_cents)}</td>
                                    <td className="text-end text-nowrap">{eur(r.vat_cents)}</td>
                                    <td className="text-end text-nowrap">{eur(r.total_cents)}</td>
                                    <td>{r.open ? <span className="badge bg-warning-subtle text-warning">{r.docstatus}</span> : r.payment.label}
                                        {r.payment.warning && <div className="fs-11 text-warning" data-testid={`pay-warning-${r.docheader_id}`}>{r.payment.warning}</div>}</td>
                                    <td className="text-nowrap">{dmy(r.due_date)}</td>
                                    <td>{r.accounting.situation ?? <span className="text-muted">—</span>}</td>
                                    <td className="text-nowrap">{dmy(r.payment.paid_on)}</td>
                                    <td className="text-nowrap">{r.accounting.sent_on ? dmy(r.accounting.sent_on) : <span className="text-muted">Por enviar</span>}</td>
                                    <td>{r.accounting.send_method_label ?? ""}</td>
                                    <td><NoteCell companyId={companyId} row={r} canEdit={canEdit} onSaved={onChanged} /></td>
                                    <td className="text-nowrap">
                                        <ActionsMenu size="sm" label={`Ações de ${r.document}`} items={[
                                            { label: "Abrir a fatura", icon: "ri-file-list-3-line", onClick: () => onOpen(r.docheader_id) },
                                            ...(canEdit && payable(r) ? [{ label: "Registar pagamento", icon: "ri-bank-card-line", onClick: () => setPaying([toPay(r)]) }] : []),
                                            ...(canEdit && reg ? [{ label: `Desfazer o pagamento de ${dmy(reg.paid_on)}`, icon: "ri-arrow-go-back-line", danger: true,
                                                onClick: () => run(`undo-${reg.id}`, () => undoFinancePayment(companyId, reg.id), "Pagamento desfeito.").then((x) => x && onChanged()) }] : []),
                                            ...(canEdit ? [r.accounting.sent
                                                ? { label: "Desmarcar o envio", icon: "ri-close-line", onClick: () => run("unsent", () => unmarkFinanceSent(companyId, [r.docheader_id]), "Envio desmarcado.").then((x) => x && onChanged()) }
                                                : { label: "Marcar como enviado", icon: "ri-send-plane-line", onClick: () => setMarking([r.docheader_id]) }] : []),
                                            ...(r.open ? [{ label: "Fechar no PingWin", icon: "ri-lock-line", disabledReason: closeReason(r), onClick: () => setClosing([toDoc(r)]) }] : []),
                                        ]} />
                                    </td>
                                </tr>
                            );
                        })}
                        {shown.length === 0 && <tr><td colSpan={17} className="text-center text-muted py-3">Sem documentos com estes filtros.</td></tr>}
                    </tbody>
                </Table>
            </div>
            {shown.length > limit && (
                <Button size="sm" color="light" className="mt-2" onClick={() => setLimit((n) => n + PAGE)}>Mostrar mais ({shown.length - limit} por mostrar)</Button>
            )}
            {closing && (
                <CloseDocumentsModal companyId={companyId} docs={closing} isOpen={!!closing} onClose={() => { setClosing(null); setSelected([]); onChanged(); }} onDone={onChanged} />
            )}
            <RegisterPaymentModal companyId={companyId} docs={paying ?? []} isOpen={!!paying} onClose={() => setPaying(null)} onDone={() => { setSelected([]); onChanged(); }} />
            <MarkSentModal companyId={companyId} ids={marking ?? []} isOpen={!!marking} onClose={() => setMarking(null)} onDone={() => { setSelected([]); onChanged(); }} />
            <AccountantEmailModal companyId={companyId} month={data.month} ids={emailing ?? []} isOpen={!!emailing} onClose={() => setEmailing(null)} onDone={() => { setSelected([]); onChanged(); }} />
            <ImportSheetModal companyId={companyId} isOpen={importing} onClose={() => setImporting(false)} onDone={onChanged} />
        </div>
    );
}
