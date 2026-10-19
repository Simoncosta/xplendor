import { useEffect, useState } from "react";
import { Alert, Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Table } from "reactstrap";
import { Link } from "react-router-dom";
import { closeSupplierDocuments, getSupplierDocumentCloseBatch } from "helpers/laravel_helper";
import { DocumentClose } from "common/models/financeMonth.model";
import { CLOSE_WARNING } from "./LaunchCard";

/**
 * XPLENDOR — "Fechar no PingWin" um documento de fornecedor em Aberto, ou "Fechar os selecionados" (no
 * máximo 10). Mostra a lista e o total, pede a confirmação e acompanha o fecho: um de cada vez, com a
 * releitura depois de cada um; para no primeiro erro e diz qual.
 */

export type CloseDoc = { docheader_id: string; document: string | null; supplier: string | null; total_cents: number };
type Props = { companyId: number; docs: CloseDoc[]; isOpen: boolean; onClose: () => void; onDone: () => void };

const MAX = 10;
const eur = (c: number) => (c / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const STATUS: Record<DocumentClose["status"], { label: string; cls: string }> = {
    pendente: { label: "A fechar…", cls: "text-muted" },
    ok: { label: "Fechado", cls: "text-success" },
    erro: { label: "Não fechou", cls: "text-danger" },
    erro_confirmacao: { label: "Rever no PingWin", cls: "text-warning" },
    nao_feito: { label: "Não foi feito", cls: "text-muted" },
};

export default function CloseDocumentsModal({ companyId, docs, isOpen, onClose, onDone }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<{ message: string; failed?: { key: string; message: string; docheader_id?: string; document?: string }[] } | null>(null);
    const [batch, setBatch] = useState<string | null>(null);
    const [closes, setCloses] = useState<DocumentClose[]>([]);
    const done = closes.length > 0 && !closes.some((c) => c.status === "pendente");

    useEffect(() => {
        if (!isOpen) { setBatch(null); setCloses([]); setError(null); setBusy(false); }
    }, [isOpen]);

    useEffect(() => {
        if (!batch || done) return;
        const t = setInterval(async () => {
            const r: any = await getSupplierDocumentCloseBatch(companyId, batch).catch(() => null);
            if (r?.data?.closes) setCloses(r.data.closes);
            if (r?.data?.done) onDone();
        }, 3000);
        return () => clearInterval(t);
    }, [batch, done, companyId, onDone]);

    const total = docs.reduce((a, d) => a + d.total_cents, 0);
    const many = docs.length > 1;
    const tooMany = docs.length > MAX;

    const submit = async () => {
        setBusy(true);
        setError(null);
        try {
            const r: any = await closeSupplierDocuments(companyId, docs.map((d) => d.docheader_id));
            setBatch(r?.data?.batch ?? null);
            setCloses(r?.data?.closes ?? []);
        } catch (e: any) {
            setError({ message: e?.message ?? "Não foi possível fechar.", failed: e?.errors?.failed });
        } finally {
            setBusy(false);
        }
    };

    const firstError = closes.find((c) => c.status === "erro" || c.status === "erro_confirmacao");

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && (!batch || done) && onClose()} centered size="lg" data-testid="close-docs-modal">
            <ModalHeader toggle={() => !busy && (!batch || done) && onClose()}>{many ? `Fechar ${docs.length} documentos no PingWin?` : "Fechar no PingWin?"}</ModalHeader>
            <ModalBody>
                {!batch && <Alert color="warning" className="py-2 fs-13" data-testid="close-docs-warning"><i className="ri-error-warning-line me-1" />{CLOSE_WARNING}</Alert>}
                {tooMany && <Alert color="danger" className="py-2 fs-13">No máximo {MAX} documentos de cada vez.</Alert>}
                {error && (
                    <Alert color="danger" className="py-2 fs-13" data-testid="close-docs-error">
                        {error.message}
                        {error.failed?.[0]?.docheader_id && <> <Link to={`/restauracao/documentos?doc=${error.failed[0].docheader_id}`}>Abrir a fatura</Link></>}
                    </Alert>
                )}
                <Table size="sm" className="fs-13 align-middle mb-2">
                    <thead className="table-light text-muted"><tr><th>Documento</th><th>Fornecedor</th><th className="text-end">Total</th>{batch && <th>Estado</th>}</tr></thead>
                    <tbody>
                        {docs.map((d) => {
                            const c = closes.find((x) => x.docheader_id === d.docheader_id);
                            return (
                                <tr key={d.docheader_id} data-testid={`close-row-${d.docheader_id}`}>
                                    <td className="fw-medium">{d.document ?? d.docheader_id}</td>
                                    <td>{d.supplier ?? "—"}</td>
                                    <td className="text-end text-nowrap">{eur(d.total_cents)}</td>
                                    {batch && <td className={c ? STATUS[c.status].cls : "text-muted"}>
                                        {c?.status === "pendente" && <Spinner size="sm" className="me-1" />}{c ? STATUS[c.status].label : "—"}
                                        {c?.error && <div className="fs-12">{c.error}</div>}
                                    </td>}
                                </tr>
                            );
                        })}
                    </tbody>
                    <tfoot><tr className="fw-semibold"><td colSpan={2}>Total</td><td className="text-end text-nowrap">{eur(total)}</td>{batch && <td />}</tr></tfoot>
                </Table>
                {batch && !done && <div className="fs-13 text-muted"><Spinner size="sm" className="me-1" />A fechar um de cada vez, com releitura no PingWin depois de cada um…</div>}
                {done && (firstError
                    ? <Alert color="danger" className="py-2 fs-13 mb-0" data-testid="close-docs-stopped">Parou em {firstError.document}: {firstError.error}</Alert>
                    : <Alert color="success" className="py-2 fs-13 mb-0" data-testid="close-docs-ok">{closes.length === 1 ? "Fechado no PingWin e confirmado pela releitura." : `${closes.length} documentos fechados no PingWin e confirmados pela releitura.`}</Alert>)}
            </ModalBody>
            <ModalFooter>
                {!batch ? (
                    <>
                        <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                        <Button color="primary" onClick={submit} disabled={busy || tooMany || docs.length === 0} data-testid="close-docs-confirm">
                            {busy ? <><Spinner size="sm" className="me-1" />A pedir…</> : many ? "Fechar os selecionados" : "Fechar no PingWin"}
                        </Button>
                    </>
                ) : (
                    <Button color="primary" onClick={onClose} disabled={!done}>Fechar a janela</Button>
                )}
            </ModalFooter>
        </Modal>
    );
}
