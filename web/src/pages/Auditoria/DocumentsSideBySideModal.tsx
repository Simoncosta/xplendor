import { useEffect, useState } from "react";
import { Button, Col, Modal, ModalBody, ModalFooter, ModalHeader, Row, Table } from "reactstrap";
import Skeleton from "Components/Common/Skeleton";
import { getSupplierDocumentLines } from "helpers/laravel_helper";

/**
 * XPLENDOR — Auditoria v2-C (C5): duas faturas de fornecedor lado a lado (do espelho, só leitura),
 * para ver se são a mesma compra lançada duas vezes. As linhas iguais (artigo, quantidade e preço)
 * ficam marcadas.
 */
type Doc = {
    document: { docheader_id: string; document: string | null; doctype: string | null; doc_date: string | null; entity_name: string | null; total: number;
        docstatus_description: string | null; docreference_number: string | null; store_name: string | null; lines_status: string | null };
    lines: { id: number; line_number: number; code: string | null; description: string | null; qnt: number; unit: string | null; price: number; total: number }[];
};
const eur = (v: number, d = 2) => `${v.toLocaleString("pt-PT", { minimumFractionDigits: d, maximumFractionDigits: d })} €`;
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT") : "—");
const lineKey = (l: Doc["lines"][number]) => `${l.code ?? l.description}|${l.qnt}|${l.price}`;

export default function DocumentsSideBySideModal({ companyId, documents, onClose }: { companyId: number; documents: string[]; onClose: () => void }) {
    const [docs, setDocs] = useState<(Doc | null)[]>([]);
    const [error, setError] = useState<string | null>(null);
    const key = documents.join(",");

    useEffect(() => {
        let alive = true;
        setDocs([]);
        Promise.all(key.split(",").map((id) => getSupplierDocumentLines(companyId, id).then((r: any) => (r?.data ?? null) as Doc | null)))
            .then((d) => { if (alive) setDocs(d); })
            .catch((e: any) => { if (alive) setError(e?.message ?? "Não foi possível ler os documentos."); });
        return () => { alive = false; };
    }, [companyId, key]);

    const keys = docs.map((d) => new Set((d?.lines ?? []).map(lineKey)));
    const shared = (k: string, i: number) => keys.some((s, j) => j !== i && s.has(k));

    return (
        <Modal isOpen toggle={onClose} size="xl" fullscreen="lg" scrollable centered data-testid="documents-side">
            <ModalHeader toggle={onClose}>Os dois documentos lado a lado</ModalHeader>
            <ModalBody>
                {error ? <p className="text-danger mb-0">{error}</p> : docs.length === 0 ? <Skeleton variant="lines" lines={6} label="A ler os documentos…" /> : (
                    <Row className="g-3">
                        {docs.map((d, i) => (
                            <Col lg={6} key={i}>
                                {!d ? <p className="text-muted">Documento não encontrado.</p> : (
                                    <div className="border rounded p-2 h-100" data-testid={`documents-side-${i}`}>
                                        <div className="fw-semibold">{d.document.doctype} {d.document.document}</div>
                                        <div className="text-muted fs-12 mb-2">{d.document.entity_name}</div>
                                        <div className="d-flex flex-wrap gap-3 fs-13 mb-2">
                                            <span><span className="text-muted">Data:</span> {day(d.document.doc_date)}</span>
                                            <span><span className="text-muted">Estado:</span> {d.document.docstatus_description ?? "—"}</span>
                                            <span><span className="text-muted">Nº doc. fornecedor:</span> {d.document.docreference_number || "—"}</span>
                                            <span><span className="text-muted">Total:</span> <strong>{eur(d.document.total)}</strong></span>
                                        </div>
                                        {d.lines.length === 0 ? <p className="text-muted fs-13 mb-0">As linhas deste documento ainda não foram lidas.</p> : (
                                            <Table size="sm" className="align-middle mb-0 fs-12">
                                                <thead className="table-light"><tr><th>Artigo</th><th className="text-end">Qtd.</th><th className="text-end">Preço</th><th className="text-end">Total</th></tr></thead>
                                                <tbody>
                                                    {d.lines.map((l) => (
                                                        <tr key={l.id} className={shared(lineKey(l), i) ? "table-warning" : undefined}>
                                                            <td>{l.description}</td>
                                                            <td className="text-end text-nowrap">{l.qnt.toLocaleString("pt-PT", { maximumFractionDigits: 3 })} {l.unit ?? ""}</td>
                                                            <td className="text-end text-nowrap">{eur(l.price, 4)}</td>
                                                            <td className="text-end text-nowrap">{eur(l.total)}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </Table>
                                        )}
                                    </div>
                                )}
                            </Col>
                        ))}
                    </Row>
                )}
            </ModalBody>
            <ModalFooter>
                <span className="text-muted fs-12 me-auto">A amarelo, as linhas iguais nos dois documentos. Só leitura: para anular um documento, faça-o no PingWin.</span>
                <Button type="button" color="light" onClick={onClose}>Fechar</Button>
            </ModalFooter>
        </Modal>
    );
}
