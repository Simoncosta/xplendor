import { useEffect, useMemo, useState } from "react";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import {
    getFinancePayables, importAccountingSheet, markFinanceSent, previewAccountantEmail, registerFinancePayment, sendAccountantEmail,
} from "helpers/laravel_helper";
import { AccountantEmailPreview, PAYMENT_METHODS, PayableSupplier, PaymentMethod, SEND_METHODS, SendMethod } from "common/models/financeMonth.model";
import { dmy, eur } from "./format";

/**
 * XPLENDOR — Finanças: os diálogos do registo de pagamentos e do envio à contabilidade. Tudo só na
 * XPLENDOR: nada se escreve no PingWin.
 */

const today = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};
export type DocRef = { docheader_id: string; document: string | null; supplier: string | null; open_cents: number };

/** "Registar pagamento" (um documento ou vários com o mesmo pagamento). */
export function RegisterPaymentModal({ companyId, docs, isOpen, onClose, onDone }: { companyId: number; docs: DocRef[]; isOpen: boolean; onClose: () => void; onDone: () => void }) {
    const [paidOn, setPaidOn] = useState(today());
    const [method, setMethod] = useState<PaymentMethod | "">("");
    const [reference, setReference] = useState("");
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (isOpen) { setPaidOn(today()); setMethod(""); setReference(""); } }, [isOpen]);
    const total = docs.reduce((a, d) => a + d.open_cents, 0);

    const submit = async () => {
        setBusy(true);
        try {
            const r: any = await registerFinancePayment(companyId, { docheader_ids: docs.map((d) => d.docheader_id), paid_on: paidOn, method, reference: reference || null });
            toast.success(r?.message ?? "Pagamento registado na XPLENDOR.");
            onDone();
            onClose();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível registar o pagamento.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered data-testid="pay-modal">
            <ModalHeader toggle={() => !busy && onClose()}>{docs.length > 1 ? `Registar um pagamento para ${docs.length} documentos` : "Registar pagamento"}</ModalHeader>
            <ModalBody>
                <Table size="sm" className="fs-13 mb-2">
                    <tbody>
                        {docs.map((d) => <tr key={d.docheader_id}><td>{d.document}</td><td>{d.supplier}</td><td className="text-end text-nowrap">{eur(d.open_cents)}</td></tr>)}
                        <tr className="fw-semibold"><td colSpan={2}>Total</td><td className="text-end text-nowrap">{eur(total)}</td></tr>
                    </tbody>
                </Table>
                <Label className="form-label mb-1" for="pay-date">Data do pagamento</Label>
                <Input id="pay-date" type="date" max={today()} value={paidOn} onChange={(e) => setPaidOn(e.target.value)} className="mb-2" />
                <Label className="form-label mb-1" for="pay-method">Forma de pagamento</Label>
                <XSelect<string> id="pay-method" ariaLabel="Forma de pagamento" value={method} placeholder="Escolher…" searchable={false}
                    options={PAYMENT_METHODS} onChange={(v) => setMethod(v as PaymentMethod)} />
                <Label className="form-label mb-1 mt-2" for="pay-ref">Referência (opcional)</Label>
                <Input id="pay-ref" maxLength={120} value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Por exemplo, o nº da transferência" />
                <div className="fs-12 text-muted mt-2">Fica registado só na XPLENDOR, como "Pago (registado na XPLENDOR)". Quando a nota de liquidação aparecer no PingWin, ganha a este registo.</div>
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <ReasonButton color="light" reason="Disponível em breve.">Lançar a nota de liquidação no PingWin</ReasonButton>
                <div className="d-flex gap-2">
                    <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                    <ReasonButton color="primary" reason={!method ? "Escolha a forma de pagamento." : !paidOn ? "Escolha a data." : null} disabled={busy} onClick={submit} data-testid="pay-confirm">
                        {busy ? <Spinner size="sm" /> : "Registar pagamento"}
                    </ReasonButton>
                </div>
            </ModalFooter>
        </Modal>
    );
}

/** "Regularizar": por fornecedor, os documentos por pagar e vencidos; vários com um só pagamento. */
export function RegularizeModal({ companyId, isOpen, onClose, onDone }: { companyId: number; isOpen: boolean; onClose: () => void; onDone: () => void }) {
    const [suppliers, setSuppliers] = useState<PayableSupplier[] | null>(null);
    const [supplier, setSupplier] = useState<number | 0>(0);
    const [selected, setSelected] = useState<string[]>([]);
    const [paying, setPaying] = useState(false);
    useEffect(() => {
        if (!isOpen) return;
        setSuppliers(null); setSupplier(0); setSelected([]);
        getFinancePayables(companyId).then((r: any) => setSuppliers(r?.data?.suppliers ?? [])).catch(() => setSuppliers([]));
    }, [isOpen, companyId]);
    const s = suppliers?.find((x) => x.supplier_id === supplier) ?? null;
    const docs: DocRef[] = useMemo(() => (s ? s.documents.filter((d) => selected.includes(d.docheader_id))
        .map((d) => ({ docheader_id: d.docheader_id, document: d.document, supplier: s.supplier, open_cents: d.open_cents })) : []), [s, selected]);

    return (
        <>
            <Modal isOpen={isOpen && !paying} toggle={onClose} centered size="lg" data-testid="regularize-modal">
                <ModalHeader toggle={onClose}>Regularizar pagamentos</ModalHeader>
                <ModalBody>
                    {!suppliers ? <Spinner size="sm" /> : suppliers.length === 0 ? <p className="text-muted mb-0">Não há documentos por pagar na conta corrente.</p> : (
                        <>
                            <Label className="form-label mb-1" for="reg-supplier">Fornecedor</Label>
                            <XSelect<number> id="reg-supplier" ariaLabel="Fornecedor" value={supplier} placeholder="Escolher…"
                                options={suppliers.map((x) => ({ value: x.supplier_id, label: `${x.supplier ?? "—"} · ${eur(x.total_cents)}${x.overdue_cents ? ` (vencido ${eur(x.overdue_cents)})` : ""}` }))}
                                onChange={(v) => { setSupplier(v); setSelected([]); }} />
                            {s && (
                                <Table size="sm" className="fs-13 align-middle mt-2 mb-0" data-testid="regularize-docs">
                                    <thead className="table-light text-muted"><tr><th style={{ width: 28 }}>
                                        <Input type="checkbox" aria-label="Escolher todos" checked={selected.length === s.documents.length}
                                            onChange={(e) => setSelected(e.target.checked ? s.documents.map((d) => d.docheader_id) : [])} />
                                    </th><th>Documento</th><th>Data</th><th>Vencimento</th><th className="text-end">Por pagar</th></tr></thead>
                                    <tbody>
                                        {s.documents.map((d) => (
                                            <tr key={d.docheader_id} className={d.overdue ? "table-danger" : undefined}>
                                                <td><Input type="checkbox" aria-label={`Escolher ${d.document}`} checked={selected.includes(d.docheader_id)}
                                                    onChange={() => setSelected((x) => (x.includes(d.docheader_id) ? x.filter((y) => y !== d.docheader_id) : [...x, d.docheader_id]))} /></td>
                                                <td>{d.document}</td><td>{dmy(d.doc_date)}</td><td>{dmy(d.due_date)}{d.overdue && <span className="text-danger"> · vencido</span>}</td>
                                                <td className="text-end text-nowrap">{eur(d.open_cents)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            )}
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={onClose}>Cancelar</Button>
                    <ReasonButton color="primary" reason={docs.length === 0 ? "Escolha os documentos." : null} onClick={() => setPaying(true)} data-testid="regularize-next">
                        Registar um pagamento ({docs.length})
                    </ReasonButton>
                </ModalFooter>
            </Modal>
            <RegisterPaymentModal companyId={companyId} docs={docs} isOpen={paying} onClose={() => { setPaying(false); onClose(); }} onDone={onDone} />
        </>
    );
}

/** "Marcar como enviado" (um ou vários documentos). */
export function MarkSentModal({ companyId, ids, isOpen, onClose, onDone }: { companyId: number; ids: string[]; isOpen: boolean; onClose: () => void; onDone: () => void }) {
    const [sentOn, setSentOn] = useState(today());
    const [method, setMethod] = useState<SendMethod>("email");
    const [busy, setBusy] = useState(false);
    const submit = async () => {
        setBusy(true);
        try {
            const r: any = await markFinanceSent(companyId, { docheader_ids: ids, sent_on: sentOn, send_method: method });
            toast.success(r?.message ?? "Marcado como enviado.");
            onDone();
            onClose();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível marcar.");
        } finally {
            setBusy(false);
        }
    };
    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered data-testid="sent-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Marcar {ids.length === 1 ? "o documento" : `${ids.length} documentos`} como enviado{ids.length === 1 ? "" : "s"}</ModalHeader>
            <ModalBody>
                <Label className="form-label mb-1" for="sent-date">Enviado em</Label>
                <Input id="sent-date" type="date" value={sentOn} onChange={(e) => setSentOn(e.target.value)} className="mb-2" />
                <Label className="form-label mb-1" for="sent-method">Forma de envio</Label>
                <XSelect<string> id="sent-method" ariaLabel="Forma de envio" value={method} searchable={false} options={SEND_METHODS} onChange={(v) => setMethod(v as SendMethod)} />
                <div className="fs-12 text-muted mt-2">Só fica registado na XPLENDOR.</div>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                <Button color="primary" onClick={submit} disabled={busy || !sentOn} data-testid="sent-confirm">{busy ? <Spinner size="sm" /> : "Marcar como enviado"}</Button>
            </ModalFooter>
        </Modal>
    );
}

const kb = (b: number) => (b >= 1024 * 1024 ? `${(b / 1024 / 1024).toLocaleString("pt-PT", { maximumFractionDigits: 1 })} MB` : `${Math.max(1, Math.round(b / 1024))} kB`);

/** "Enviar ao contabilista": primeiro a pré-visualização; só envia com o clique. */
export function AccountantEmailModal({ companyId, month, ids, isOpen, onClose, onDone }: { companyId: number; month: string; ids: string[]; isOpen: boolean; onClose: () => void; onDone: () => void }) {
    const [p, setP] = useState<AccountantEmailPreview | null>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        if (!isOpen) return;
        setP(null);
        previewAccountantEmail(companyId, { month, docheader_ids: ids }).then((r: any) => setP(r?.data ?? null)).catch((e: any) => toast.error(e?.message ?? "Não foi possível preparar o email."));
    }, [isOpen, companyId, month, ids]);
    const send = async () => {
        setBusy(true);
        try {
            const r: any = await sendAccountantEmail(companyId, { month, docheader_ids: ids });
            toast.success(r?.message ?? "Enviado ao contabilista.");
            onDone();
            onClose();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível enviar.");
        } finally {
            setBusy(false);
        }
    };
    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered size="lg" data-testid="email-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Enviar ao contabilista</ModalHeader>
            <ModalBody>
                {!p ? <Spinner size="sm" /> : (
                    <div data-testid="email-preview">
                        <dl className="row fs-13 mb-2">
                            <dt className="col-3">Para</dt><dd className="col-9">{p.to ?? <span className="text-danger">sem email</span>}</dd>
                            <dt className="col-3">Assunto</dt><dd className="col-9">{p.subject}</dd>
                        </dl>
                        {p.lines.map((l) => <p key={l} className="fs-13 mb-1">{l}</p>)}
                        <Table size="sm" className="fs-13 mt-2 mb-2">
                            <thead className="table-light text-muted"><tr><th>Documento</th><th>Fornecedor</th><th>Data</th><th className="text-end">Valor</th></tr></thead>
                            <tbody>{p.documents.map((d) => <tr key={d.docheader_id}><td>{d.number}</td><td>{d.supplier}</td><td>{d.date}</td><td className="text-end text-nowrap">{d.value}</td></tr>)}</tbody>
                        </Table>
                        <div className="fs-13"><strong>Anexos ({kb(p.bytes)}):</strong> {p.attachments.map((a) => `${a.name} (${kb(a.bytes)})`).join(", ")}</div>
                        {p.missing.length > 0 && <div className="fs-13 text-muted mt-1">Sem ficheiro na XPLENDOR: {p.missing.map((m) => m.document).join(", ")}.</div>}
                        {p.problems.map((x) => <Alert key={x} color="warning" className="py-2 fs-13 mt-2 mb-0">{x}</Alert>)}
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                <ReasonButton color="primary" reason={!p ? "A preparar…" : p.can_send ? null : p.problems.join(" ")} disabled={busy} onClick={send} data-testid="email-send">
                    {busy ? <Spinner size="sm" /> : "Enviar"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

/** "Importar folha antiga (XLSX)": emparelha por NIF e nº do documento; mostra o que ficou sem par. */
export function ImportSheetModal({ companyId, isOpen, onClose, onDone }: { companyId: number; isOpen: boolean; onClose: () => void; onDone: () => void }) {
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [res, setRes] = useState<{ matched: number; unmatched: { row: number; nif: string | null; titular: string | null; number: string; reason: string }[] } | null>(null);
    useEffect(() => { if (isOpen) { setFile(null); setRes(null); } }, [isOpen]);
    const submit = async () => {
        if (!file) return;
        setBusy(true);
        try {
            const r: any = await importAccountingSheet(companyId, file);
            setRes(r?.data ?? null);
            onDone();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível importar a folha.");
        } finally {
            setBusy(false);
        }
    };
    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered size="lg" data-testid="import-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Importar a folha antiga</ModalHeader>
            <ModalBody>
                {!res ? (
                    <>
                        <p className="fs-13">A folha "Documentos enviados para a contabilidade" em XLSX. Cada linha emparelha por NIF e nº do documento e preenche a Situação, o Enviado em, a Forma de envio e a Nota.</p>
                        <Input type="file" accept=".xlsx,.xls" aria-label="Folha em XLSX" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                    </>
                ) : (
                    <div data-testid="import-result">
                        <p className="fs-13 mb-2"><strong>{res.matched}</strong> linhas emparelhadas; <strong>{res.unmatched.length}</strong> sem par.</p>
                        {res.unmatched.length > 0 && (
                            <Table size="sm" className="fs-13 mb-0">
                                <thead className="table-light text-muted"><tr><th>Linha</th><th>NIF</th><th>Titular</th><th>Nº do documento</th><th>Motivo</th></tr></thead>
                                <tbody>{res.unmatched.map((u) => <tr key={u.row}><td>{u.row}</td><td>{u.nif ?? "—"}</td><td>{u.titular ?? "—"}</td><td>{u.number}</td><td>{u.reason}</td></tr>)}</tbody>
                            </Table>
                        )}
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>{res ? "Fechar" : "Cancelar"}</Button>
                {!res && <ReasonButton color="primary" reason={file ? null : "Escolha a folha."} disabled={busy} onClick={submit} data-testid="import-confirm">{busy ? <Spinner size="sm" /> : "Importar"}</ReasonButton>}
            </ModalFooter>
        </Modal>
    );
}
