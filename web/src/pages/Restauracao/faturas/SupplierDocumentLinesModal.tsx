import { useEffect, useState } from "react";
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import Skeleton from "Components/Common/Skeleton";
import { getSupplierDocumentLines } from "helpers/laravel_helper";

/**
 * XPLENDOR — as linhas de um documento de fornecedor do PingWin (do espelho, só leitura). Abre-se
 * na lista dos documentos e pelo "Corrigir" da Auditoria (preço fora do normal, data fora da série,
 * fatura sem linhas): ?tab=pingwin&doc={docheader_id}.
 */
type DocLines = {
    document: { docheader_id: string; document: string | null; doctype: string | null; doc_date: string | null; entity_name: string | null; tax_number: string | null;
        total: number; docstatus_description: string | null; docreference_number: string | null; store_name: string | null; lines_status: string | null };
    lines: { id: number; line_number: number; code: string | null; description: string | null; qnt: number; unit: string | null; price: number; discount: number; total: number; tax: number; tax_description: string | null }[];
};

const eur = (v: number, d = 2) => `${v.toLocaleString("pt-PT", { minimumFractionDigits: d, maximumFractionDigits: d })} €`;
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT") : "—");

export default function SupplierDocumentLinesModal({ companyId, docheaderId, onClose }: { companyId: number; docheaderId: string; onClose: () => void }) {
    const [data, setData] = useState<DocLines | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let alive = true;
        setData(null);
        setError(null);
        getSupplierDocumentLines(companyId, docheaderId)
            .then((res: any) => { if (alive) setData(res?.data ?? null); })
            .catch((e: any) => { if (alive) setError(e?.message ?? "Não foi possível ler o documento."); });
        return () => { alive = false; };
    }, [companyId, docheaderId]);

    const d = data?.document;
    return (
        <Modal isOpen toggle={onClose} size="xl" fullscreen="sm" scrollable centered data-testid="document-lines">
            <ModalHeader toggle={onClose}>
                {d ? <>{d.doctype} {d.document}<div className="fs-13 text-muted fw-normal">{d.entity_name}{d.tax_number ? ` · NIF ${d.tax_number}` : ""}</div></> : "Documento"}
            </ModalHeader>
            <ModalBody>
                {error ? <p className="text-danger mb-0">{error}</p> : !data ? <Skeleton variant="lines" lines={5} label="A ler o documento…" /> : (
                    <>
                        <div className="d-flex flex-wrap gap-4 fs-13 mb-3">
                            <span><span className="text-muted">Data:</span> {day(d!.doc_date)}</span>
                            <span><span className="text-muted">Estado:</span> {d!.docstatus_description ?? "—"}</span>
                            <span><span className="text-muted">Loja:</span> {d!.store_name ?? "—"}</span>
                            <span><span className="text-muted">Nº doc. fornecedor:</span> {d!.docreference_number || "—"}</span>
                            <span><span className="text-muted">Total:</span> <strong>{eur(d!.total)}</strong></span>
                        </div>
                        {data.lines.length === 0 ? (
                            <p className="text-muted mb-0">{d!.lines_status ? "Este documento não tem linhas." : "As linhas deste documento ainda não foram lidas (só se leem quando o documento está fechado no PingWin)."}</p>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-sm align-middle mb-0 fs-13">
                                    <thead className="table-light">
                                        <tr><th>#</th><th>Artigo</th><th className="text-end">Quantidade</th><th>Unidade</th><th className="text-end">Preço</th><th className="text-end">Desconto</th><th className="text-end">Total</th><th>IVA</th></tr>
                                    </thead>
                                    <tbody>
                                        {data.lines.map((l) => (
                                            <tr key={l.id}>
                                                <td className="text-muted">{l.line_number}</td>
                                                <td>{l.description}{l.code && <div className="text-muted fs-12">{l.code}</div>}</td>
                                                <td className="text-end text-nowrap">{l.qnt.toLocaleString("pt-PT", { maximumFractionDigits: 3 })}</td>
                                                <td className="text-nowrap">{l.unit ?? "—"}</td>
                                                <td className="text-end text-nowrap">{eur(l.price, 4)}</td>
                                                <td className="text-end text-nowrap">{l.discount ? `${l.discount.toLocaleString("pt-PT")}%` : "—"}</td>
                                                <td className="text-end text-nowrap fw-medium">{eur(l.total)}</td>
                                                <td className="text-nowrap">{l.tax_description ?? "—"}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <span className="text-muted fs-12 me-auto">Só leitura: para corrigir, abra o documento no PingWin.</span>
                <Button type="button" color="light" onClick={onClose}>Fechar</Button>
            </ModalFooter>
        </Modal>
    );
}
