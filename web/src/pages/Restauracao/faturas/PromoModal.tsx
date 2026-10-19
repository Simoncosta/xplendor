import { useEffect, useMemo, useState } from "react";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Table } from "reactstrap";
import { OcrInvoiceDiscount, OcrInvoiceLine } from "common/models/ocr.model";

/**
 * XPLENDOR — Faturas: a promoção de uma linha ("Promoção…" no menu da linha). O código (pré-preenchido
 * com o DD lido pelo OCR), a linha de desconto da fatura com esse código, as outras linhas com o mesmo
 * código e a repartição do desconto com o líquido de cada uma. "Aplicar" muda só o código da linha: a
 * repartição que conta é a do servidor (InvoiceDiscountAllocator), refeita ao revalidar.
 */

const eur = (n?: number | null) => (n ?? 0).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
/** O código como chave (sem espaços, maiúsculas, sem zeros à esquerda), como no servidor. */
export const promoKey = (v?: string | null) => (v ?? "").replace(/\s+/g, "").toUpperCase().replace(/^0+/, "");

/**
 * A repartição de um desconto (cêntimos) pelos valores das linhas (cêntimos): a parte inteira de cada
 * uma e o resto dos cêntimos na maior (a primeira, se houver empate), a mesma regra do servidor.
 * Só para mostrar antes de aplicar.
 */
export function splitPreview(amount: number, weights: number[]): number[] {
    const total = weights.reduce((a, w) => a + w, 0);
    if (total <= 0) return weights.map((_, k) => (k === 0 ? amount : 0));
    const shares = weights.map((w) => Math.floor((amount * w) / total));
    const largest = weights.indexOf(Math.max(...weights));
    shares[largest] += amount - shares.reduce((a, s) => a + s, 0);
    return shares;
}

/** A etiqueta por baixo do Item: a promoção e o desconto, ou o aviso de que não há desconto na fatura. */
export function promoTag(line: OcrInvoiceLine, discounts: OcrInvoiceDiscount[]): { text: string; warn: boolean } | null {
    const code = promoKey(line.promo_code);
    if (!code) return null;
    const has = discounts.some((d) => promoKey(d.code) === code);
    if (!has) return { text: "Promoção sem desconto na fatura", warn: true };
    return { text: `Promoção ${line.promo_code} · −${eur(line.discount ?? 0)}`, warn: false };
}

type Props = {
    isOpen: boolean;
    index: number | null;
    lines: OcrInvoiceLine[];
    discounts: OcrInvoiceDiscount[];
    busy: boolean;
    onClose: () => void;
    onApply: (index: number, code: string | null) => void;
};

export default function PromoModal({ isOpen, index, lines, discounts, busy, onClose, onApply }: Props) {
    const line = index !== null ? lines[index] : null;
    const [code, setCode] = useState("");
    useEffect(() => { if (isOpen) setCode(line?.promo_code ?? ""); }, [isOpen, line?.promo_code]);
    const key = promoKey(code);

    const view = useMemo(() => {
        if (!key || index === null) return null;
        const matches = discounts.filter((d) => promoKey(d.code) === key);
        const amount = Math.round(matches.reduce((a, d) => a + d.amount, 0) * 100);
        // As linhas com o mesmo código (com esta, se o código mudar).
        const idx = lines.map((l, k) => ({ l, k })).filter(({ l, k }) => (k === index ? true : promoKey(l.promo_code) === key));
        const gross = idx.map(({ l }) => Math.abs(Math.round((l.line_total ?? 0) * 100)));
        const shares = splitPreview(amount, gross);
        return { matches, amount, rows: idx.map(({ l, k }, j) => ({ k, l, gross: gross[j], share: matches.length ? shares[j] : 0 })) };
    }, [key, index, lines, discounts]);

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered size="lg" data-testid="promo-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Promoção da linha {index !== null ? index + 1 : ""}</ModalHeader>
            <ModalBody>
                <p className="fs-13 text-muted mb-2">{line?.item ?? "—"}</p>
                <Label className="form-label mb-1" for="promo-code">Código da promoção</Label>
                <Input id="promo-code" className="font-monospace mb-1" style={{ maxWidth: 220 }} value={code} onChange={(e) => setCode(e.target.value)} placeholder="Por exemplo, 43520" />
                {line?.promo_code && <div className="fs-12 text-muted mb-3">Lido pelo OCR na coluna da promoção: {line.promo_code}.</div>}

                {view && (view.matches.length === 0 ? (
                    <Alert color="warning" className="py-2 fs-13 mt-2" data-testid="promo-no-discount">Promoção sem desconto na fatura: não há nenhuma linha de desconto com o código {code}.</Alert>
                ) : (
                    <div className="mt-2" data-testid="promo-view">
                        <div className="fs-13 mb-2">
                            {view.matches.map((d) => <div key={d.id} className="fw-medium">{d.label ?? "Desconto"} {d.code}: −{eur(d.amount)}{d.status === "por_atribuir" && <span className="badge bg-warning-subtle text-warning ms-2">por atribuir</span>}</div>)}
                        </div>
                        <Table size="sm" className="fs-13 align-middle mb-1">
                            <thead className="table-light text-muted"><tr><th>Linha</th><th className="text-end">Valor</th><th className="text-end">Desconto</th><th className="text-end">Líquido</th></tr></thead>
                            <tbody>
                                {view.rows.map((r) => (
                                    <tr key={r.k} className={r.k === index ? "fw-semibold" : undefined}>
                                        <td>{r.k + 1} · {r.l.item ?? "—"}</td>
                                        <td className="text-end text-nowrap">{eur(r.gross / 100)}</td>
                                        <td className="text-end text-nowrap">−{eur(r.share / 100)}</td>
                                        <td className="text-end text-nowrap">{eur((r.gross - r.share) / 100)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <div className="fs-12 text-muted">Repartido pelo valor de cada linha, com o resto dos cêntimos na maior. Ao aplicar, a XPLENDOR revalida a fatura e grava a repartição.</div>
                    </div>
                ))}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <Button color="outline-danger" onClick={() => index !== null && onApply(index, null)} disabled={busy || !line?.promo_code} data-testid="promo-remove">Remover promoção</Button>
                <div className="d-flex gap-2">
                    <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                    <Button color="primary" onClick={() => index !== null && onApply(index, code.trim() || null)} disabled={busy || !code.trim()} data-testid="promo-apply">Aplicar</Button>
                </div>
            </ModalFooter>
        </Modal>
    );
}
