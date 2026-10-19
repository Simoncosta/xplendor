import { useMemo } from "react";
import { Button, Offcanvas, OffcanvasBody, OffcanvasHeader, Table } from "reactstrap";
import { FinanceDocumentRow, FinanceMonth } from "common/models/financeMonth.model";
import ResultadoTab, { groupRows } from "./ResultadoTab";
import TesourariaTab from "./TesourariaTab";
import DocumentosTab from "./DocumentosTab";
import { DetailKind } from "./ResumoTab";
import { dmy, eur, eur0, GROUP_COLORS, GROUP_NAMES, pct } from "./format";

/**
 * XPLENDOR — Finanças › Mês: a segunda camada, num painel lateral. O detalhe de um grupo (as famílias em
 * barras, os 5 fornecedores com mais custo e os documentos), o resultado por grupo e família (com o mês
 * anterior, a média de 3 meses, o food cost, o IVA estimado e o que fica fora do resultado), o dinheiro
 * (a tesouraria, só para ver) e todos os documentos do mês.
 */
type Props = {
    kind: DetailKind | null; data: FinanceMonth; companyId: number | null;
    onClose: () => void; onDetail: (kind: DetailKind) => void; onOpen: (docheaderId: string) => void; onChanged: () => void;
};

/** O que cada documento tem num grupo (as partes do documento; sem partes, o documento todo). */
export function docCentsIn(r: FinanceDocumentRow, group: string): number {
    if (r.parts.length === 0) return r.group === group ? r.net_cents : 0;
    return r.parts.filter((p) => p.group === group).reduce((a, p) => a + p.cents, 0);
}

function GroupDetail({ group, data, onOpen, onDetail }: { group: string; data: FinanceMonth; onOpen: (id: string) => void; onDetail: (k: DetailKind) => void }) {
    const block = useMemo(() => groupRows(data.result.rows).find((g) => g.head.kind === "grupo" && g.head.group === group), [data.result.rows, group]);
    const head = block?.head;
    const families = useMemo(() => [...(block?.shown ?? [])].sort((a, b) => b.cents - a.cents), [block]);
    const docs = useMemo(() => data.documents.rows.map((r) => ({ r, cents: docCentsIn(r, group) })).filter((x) => x.cents !== 0)
        .sort((a, b) => b.cents - a.cents), [data.documents.rows, group]);
    const suppliers = useMemo(() => {
        const by = new Map<string, number>();
        docs.forEach(({ r, cents }) => by.set(r.supplier ?? "—", (by.get(r.supplier ?? "—") ?? 0) + cents));
        return Array.from(by.entries()).sort((a, b) => b[1] - a[1]).slice(0, 5);
    }, [docs]);
    if (!head) return <p className="text-muted fs-13">Sem custos neste grupo em {data.month_label}.</p>;
    const total = head.cents;
    const max = Math.max(1, ...families.map((f) => f.cents));
    const color = GROUP_COLORS[group] ?? "#b8c0c9";
    const share = (c: number) => (total !== 0 ? pct(Math.round((c / total) * 1000) / 10) : "—");
    const fc = data.result.food_cost;

    return (
        <div data-testid={`group-detail-${group}`}>
            <div className="d-flex flex-wrap align-items-baseline gap-2 mb-3">
                <span className="fs-3 fw-semibold">{eur0(total)}</span>
                <span className="text-muted fs-13">{pct(head.pct)} das vendas · mês anterior {eur0(head.prev_cents)} · média de 3 meses {eur0(head.avg3_cents)}</span>
            </div>
            {(group === "comida" || group === "bebida") && (
                <p className="fs-13 mb-3" data-testid="group-food-cost">Food cost de {group === "comida" ? "comida" : "bebidas"}: <strong>{pct(group === "comida" ? fc.comida_pct : fc.bebida_pct)}</strong> das vendas (total {pct(fc.pct)}).</p>
            )}

            <h6 className="fs-13 fw-semibold mb-2">Por família</h6>
            <ul className="list-unstyled mb-4" data-testid="group-families">
                {families.map((f) => (
                    <li key={f.label} className="mb-2">
                        <div className="d-flex justify-content-between fs-13 gap-2"><span className="text-truncate" title={f.label}>{f.label}</span>
                            <span className="text-nowrap"><span className="fw-semibold">{eur0(f.cents)}</span><span className="text-muted ms-2">{share(f.cents)}</span></span></div>
                        <div className="rounded" style={{ height: 8, background: "var(--vz-light)" }}>
                            <div className="rounded" style={{ height: 8, width: `${Math.max(2, (Math.max(0, f.cents) / max) * 100)}%`, background: color }} />
                        </div>
                    </li>
                ))}
                {families.length === 0 && <li className="text-muted fs-13">Sem famílias com custos neste mês.</li>}
            </ul>

            <h6 className="fs-13 fw-semibold mb-2">Os 5 fornecedores com mais custo</h6>
            <ol className="ps-3 mb-4 fs-13" data-testid="group-suppliers">
                {suppliers.map(([s, c]) => <li key={s} className="d-flex justify-content-between gap-2"><span>{s}</span><span className="text-nowrap"><span className="fw-semibold">{eur0(c)}</span><span className="text-muted ms-2">{share(c)}</span></span></li>)}
            </ol>

            <h6 className="fs-13 fw-semibold mb-2">Documentos ({docs.length})</h6>
            <div className="table-responsive">
                <Table size="sm" hover className="fs-13 align-middle mb-2" data-testid="group-docs">
                    <caption className="visually-hidden">Documentos de {head.label}</caption>
                    <thead className="table-light text-muted"><tr><th>Data</th><th>Fornecedor</th><th>Documento</th><th>Família</th><th className="text-end">Neste grupo</th><th className="text-end">Documento sem IVA</th></tr></thead>
                    <tbody>
                        {docs.map(({ r, cents }) => (
                            <tr key={r.docheader_id}>
                                <td className="text-nowrap">{dmy(r.doc_date)}</td>
                                <td>{r.supplier ?? "—"}</td>
                                <td className="text-nowrap"><button type="button" className="btn btn-link p-0 fs-13" onClick={() => onOpen(r.docheader_id)}>{r.document}</button></td>
                                <td className="text-muted">{r.family ?? r.group_label}</td>
                                <td className="text-end text-nowrap">{eur(cents)}</td>
                                <td className="text-end text-nowrap">{eur(r.net_cents)}</td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </div>
            <Button size="sm" color="link" className="p-0" onClick={() => onDetail("resultado")}>Ver a tabela de todos os grupos e famílias</Button>
        </div>
    );
}

export default function DetailPanel({ kind, data, companyId, onClose, onDetail, onOpen, onChanged }: Props) {
    const group = kind?.startsWith("grupo-") ? kind.slice(6) : null;
    const groupRow = group ? data.result.rows.find((r) => r.kind === "grupo" && r.group === group) : null;
    const title = group ? (GROUP_NAMES[group]?.hint ? `${GROUP_NAMES[group].short} (${GROUP_NAMES[group].hint.toLowerCase()})` : groupRow?.label ?? group)
        : kind === "resultado" ? "Resultado por grupo e família" : kind === "dinheiro" ? "Dinheiro: entradas, saídas e por pagar" : kind === "documentos" ? "Documentos do mês" : "";
    const wide = kind === "documentos" || kind === "dinheiro";

    return (
        <Offcanvas isOpen={!!kind} toggle={onClose} direction="end" style={{ width: wide ? "min(1200px, 100vw)" : "min(760px, 100vw)" }} data-testid="detail-panel">
            <OffcanvasHeader toggle={onClose}>
                <span className="d-flex align-items-center gap-2">
                    {group && <span className="d-inline-block rounded-circle" style={{ width: 12, height: 12, background: GROUP_COLORS[group] ?? "#b8c0c9" }} aria-hidden />}
                    {title} <span className="fs-13 text-muted fw-normal">· {data.month_label}</span>
                </span>
            </OffcanvasHeader>
            <OffcanvasBody>
                {group && <GroupDetail group={group} data={data} onOpen={onOpen} onDetail={onDetail} />}
                {kind === "resultado" && (
                    <>
                        <ResultadoTab data={data} />
                        <Button size="sm" color="light" onClick={() => onDetail("documentos")} data-testid="detail-documentos"><i className="ri-file-list-3-line me-1" />Ver todos os documentos do mês</Button>
                    </>
                )}
                {kind === "dinheiro" && companyId && <TesourariaTab companyId={companyId} data={data} onOpen={onOpen} onChanged={onChanged} readOnly />}
                {kind === "documentos" && companyId && <DocumentosTab companyId={companyId} data={data} onOpen={onOpen} onChanged={onChanged} />}
            </OffcanvasBody>
        </Offcanvas>
    );
}
