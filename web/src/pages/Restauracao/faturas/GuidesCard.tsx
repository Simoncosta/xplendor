import { Fragment, useEffect, useState } from "react";
import { Badge, Button, Table } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { getOcrInvoiceGuides } from "helpers/laravel_helper";

/**
 * XPLENDOR — Fatia 5 · "Conferência com as guias" (só leitura): cada guia referida na fatura contra os
 * documentos do mesmo fornecedor no PingWin (pelo número, pela data e pelas linhas). Guia em falta,
 * guia a mais e as diferenças de quantidade, preço e desconto, cada uma com €. "Lançar guia" abre o
 * lançamento que já existe nesta página: nada se grava sozinho.
 */
type Line = { item: string; qty: number; unit: string | null; price: number; discount: number; total_cents: number; vat?: number };
type GuideRow = {
    ref: string; date: string | null; status: "lancada" | "com_diferencas" | "em_falta"; method: "numero" | "data" | null;
    doc: { docheader_id: string; document: string; date: string; total_cents: number } | null;
    lines: { ocr: Line; pingwin: Line | null; diffs: { kind: "quantidade" | "preco" | "desconto"; eur_cents: number }[] }[];
    eur_cents: number;
};
type Reconciliation = {
    guides: GuideRow[];
    extra: { docheader_id: string; document: string; date: string; total_cents: number; eur_cents: number }[];
    summary: { referred: number; launched: number; missing: number; missing_eur_cents: number; extra_eur_cents: number; line_diff_eur_cents: number; period: { from: string | null; to: string | null } };
};

const eur = (c: number) => (c / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const day = (d: string | null) => (d ? d.split("-").reverse().join("/") : "—");
const qty = (n: number) => n.toLocaleString("pt-PT", { maximumFractionDigits: 3 });
const STATUS: Record<GuideRow["status"], [string, string]> = {
    lancada: ["Lançada", "bg-success-subtle text-success"], com_diferencas: ["Com diferenças", "bg-warning-subtle text-warning"], em_falta: ["Em falta", "bg-danger-subtle text-danger"],
};
const DIFF: Record<string, string> = { quantidade: "quantidade", preco: "preço", desconto: "desconto" };

export default function GuidesCard({ companyId, invoiceId, onLaunch }: { companyId: number; invoiceId: number; onLaunch: (guide: GuideRow) => void }) {
    const [data, setData] = useState<Reconciliation | null | undefined>(undefined);
    const [open, setOpen] = useState<string | null>(null);

    useEffect(() => {
        let alive = true;
        getOcrInvoiceGuides(companyId, invoiceId)
            .then((r: any) => { if (alive) setData(r?.data?.reconciliation ?? null); })
            .catch(() => { if (alive) setData(null); });
        return () => { alive = false; };
    }, [companyId, invoiceId]);

    if (data === null) return null;   // a fatura não refere guias
    const s = data?.summary;
    return (
        <div id="guias">
            <PageCard title="Conferência com as guias" flush={false} data-testid="guides-card"
                info="Cada guia referida na fatura é procurada no PingWin, no mesmo fornecedor: pelo número, senão pela data, e confirmada pelas linhas. Só leitura: para lançar uma guia em falta, use o lançamento desta página."
                status={s ? <span data-testid="guides-summary">{s.launched} de {s.referred} guias lançadas{s.missing ? <> · <strong className="text-danger">{s.missing} em falta: {eur(s.missing_eur_cents)}</strong></> : null}</span> : undefined}>
                {!data ? <Skeleton variant="lines" lines={4} label="A conferir as guias…" /> : (
                    <>
                        <div className="table-responsive">
                            <Table size="sm" className="align-middle mb-2 fs-13">
                                <thead className="table-light">
                                    <tr><th>Guia</th><th>Data</th><th>Estado</th><th>No PingWin</th><th className="text-end text-nowrap">€ com IVA</th><th /></tr>
                                </thead>
                                <tbody>
                                    {data.guides.map((g) => (
                                        <Fragment key={g.ref}>
                                            <tr data-testid={`guide-${g.status}`}>
                                                <td className="fw-medium text-nowrap">{g.ref}</td>
                                                <td className="text-nowrap">{day(g.date)}</td>
                                                <td><span className={`badge ${STATUS[g.status][1]}`}>{STATUS[g.status][0]}</span></td>
                                                <td className="text-nowrap">{g.doc ? <>{g.doc.document} <span className="text-muted fs-12">({day(g.doc.date)}, {g.method === "numero" ? "pelo número" : "pela data"})</span></> : "—"}</td>
                                                <td className="text-end text-nowrap">{g.eur_cents ? eur(g.eur_cents) : "—"}</td>
                                                <td className="text-end text-nowrap">
                                                    {g.lines.length > 0 && <Button size="sm" color="link" className="p-0 me-2" onClick={() => setOpen(open === g.ref ? null : g.ref)}>{open === g.ref ? "Esconder linhas" : "Ver linhas"}</Button>}
                                                    {g.status === "em_falta" && <Button size="sm" color="primary" onClick={() => onLaunch(g)} data-testid="guide-launch">Lançar guia</Button>}
                                                </td>
                                            </tr>
                                            {open === g.ref && (
                                                <tr>
                                                    <td colSpan={6} className="bg-light-subtle">
                                                        {g.lines.map((l, j) => (
                                                            <div key={j} className="d-flex flex-wrap gap-2 fs-12 py-1">
                                                                <span className="fw-medium">{l.ocr.item}</span>
                                                                <span>{qty(l.ocr.qty)} {l.ocr.unit ?? ""} × {eur(Math.round(l.ocr.price * 100))}</span>
                                                                {l.pingwin && <span className="text-muted">PingWin: {qty(l.pingwin.qty)} × {eur(Math.round(l.pingwin.price * 100))}</span>}
                                                                {l.diffs.map((d, k) => <Badge key={k} color="warning" className="bg-warning-subtle text-warning">{DIFF[d.kind]}: {eur(d.eur_cents)}</Badge>)}
                                                            </div>
                                                        ))}
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                        {data.extra.length > 0 && (
                            <div className="alert alert-warning fs-13 py-2 mb-2" data-testid="guides-extra">
                                <strong>Lançadas e não referidas na fatura:</strong> {data.extra.map((d) => `${d.document} (${day(d.date)}, ${eur(d.eur_cents)})`).join("; ")}.
                            </div>
                        )}
                        {s && s.line_diff_eur_cents !== 0 && (
                            <div className="fs-13 text-warning">Diferenças nas linhas das guias lançadas: {eur(s.line_diff_eur_cents)}.</div>
                        )}
                    </>
                )}
            </PageCard>
        </div>
    );
}
