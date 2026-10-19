import { Fragment, useMemo, useState } from "react";
import { Alert, Col, Row, Table } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import { FinanceMonth, ResultRow } from "common/models/financeMonth.model";
import { eur, pct } from "./format";

/**
 * XPLENDOR — Finanças › Mês › Resultado: por grupo e por família (valor, % das vendas, mês anterior e
 * média dos 3 meses anteriores), o food cost (o da Engenharia de menu), o IVA estimado e o que fica
 * fora do resultado. É a segunda camada do Resumo (o painel "Ver o detalhe") e a tabela do fecho do mês
 * (printable: as famílias sem gastos não se mostram); os últimos 6 meses estão no Resumo.
 */
/** Quantos segmentos iniciais são iguais em todas as famílias (deixando sempre pelo menos um). */
function commonPrefix(parts: string[][]): number {
    if (parts.length < 2) return 0;
    const max = Math.min(...parts.map((p) => p.length)) - 1;
    for (let n = 0; n < max; n++) {
        const seg = parts[0][n];
        if (parts.some((p) => p[n] !== seg)) return n;
    }
    return Math.max(0, max);
}

/** As linhas por grupo: as famílias com gasto no mês e as outras (só nos meses anteriores), sem o prefixo comum. */
export function groupRows(rows: ResultRow[]) {
    const out: { head: ResultRow; shown: ResultRow[]; hidden: ResultRow[] }[] = [];
    for (const row of rows) {
        if (row.kind !== "familia") { out.push({ head: row, shown: [], hidden: [] }); continue; }
        const g = out[out.length - 1];
        (row.cents !== 0 ? g.shown : g.hidden).push(row);
    }
    for (const g of out) {
        const fam = [...g.shown, ...g.hidden];
        const common = commonPrefix(fam.map((f) => f.label.split(" › ")));
        const strip = (f: ResultRow) => ({ ...f, label: f.label.split(" › ").slice(common).join(" › ") });
        g.shown = g.shown.map(strip);
        g.hidden = g.hidden.map(strip);
    }
    return out;
}

export default function ResultadoTab({ data, printable = false }: { data: FinanceMonth; printable?: boolean }) {
    const r = data.result;
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const groups = useMemo(() => groupRows(r.rows), [r.rows]);

    return (
        <div data-testid="finance-result">
            <Alert color="info" className="py-2 fs-13"><i className="ri-information-line me-1" />{r.store_note}</Alert>
            <div className="table-responsive mb-3">
                <Table size="sm" className="fs-13 align-middle mb-0" data-testid="result-table">
                    <caption className="visually-hidden">Gastos por grupo e por família</caption>
                    <thead className="table-light text-muted">
                        <tr><th>Grupo e família</th><th className="text-end">Valor</th><th className="text-end">% das vendas</th><th className="text-end">Mês anterior</th><th className="text-end">Média de 3 meses</th></tr>
                    </thead>
                    <tbody>
                        {groups.map(({ head, shown, hidden }, gi) => {
                            const key = `${head.kind}-${head.group}-${gi}`;
                            const open = !!expanded[key];
                            const line = (row: ResultRow, i: number) => (
                                <tr key={`${key}-${row.label}-${i}`} className={row.kind === "grupo" ? "fw-semibold table-light" : row.kind === "total" ? "fw-bold border-top" : undefined}
                                    data-testid={row.kind === "grupo" ? `result-group-${row.group}` : undefined}>
                                    <td className={row.kind === "familia" ? "ps-4 text-muted" : undefined}>{row.label}</td>
                                    <td className="text-end text-nowrap">{eur(row.cents)}</td>
                                    <td className="text-end">{pct(row.pct)}</td>
                                    <td className="text-end text-nowrap">{eur(row.prev_cents)} <span className="text-muted">({pct(row.prev_pct)})</span></td>
                                    <td className="text-end text-nowrap">{eur(row.avg3_cents)} <span className="text-muted">({pct(row.avg3_pct)})</span></td>
                                </tr>
                            );
                            return (
                                <Fragment key={key}>
                                    {line(head, -1)}
                                    {shown.map(line)}
                                    {hidden.length > 0 && !printable && (open ? hidden.map(line) : (
                                        <tr><td colSpan={5} className="ps-4">
                                            <button type="button" className="btn btn-link btn-sm p-0 fs-12" onClick={() => setExpanded((e) => ({ ...e, [key]: true }))}>
                                                Mostrar {hidden.length} {hidden.length === 1 ? "família" : "famílias"} sem gastos este mês
                                            </button>
                                        </td></tr>
                                    ))}
                                </Fragment>
                            );
                        })}
                    </tbody>
                </Table>
            </div>
            <Row className="g-3 mb-3">
                <Col md={6}>
                    <div className="p-3 border rounded h-100" data-testid="result-food-cost">
                        <div className="text-muted fs-12 text-uppercase fw-semibold d-flex align-items-center gap-1 mb-1">Food cost do mês<InfoTip label="Food cost do mês" text={r.food_cost.source} /></div>
                        <div className="fs-4 fw-semibold">{pct(r.food_cost.pct)}</div>
                        <div className="fs-12 text-muted">Comida {pct(r.food_cost.comida_pct)} · Bebidas {pct(r.food_cost.bebida_pct)} · média de 3 meses {pct(r.food_cost.avg3_pct)}</div>
                    </div>
                </Col>
                <Col md={6}>
                    <div className="p-3 border rounded h-100" data-testid="result-vat">
                        <div className="text-muted fs-12 text-uppercase fw-semibold d-flex align-items-center gap-1 mb-1">IVA estimado<InfoTip label="IVA estimado" text="O IVA das vendas por artigo do mês menos o IVA dedutível das faturas de fornecedor do mês (as notas de crédito subtraem)." /></div>
                        <div className="fs-4 fw-semibold">{eur(r.vat.estimate_cents)}</div>
                        <div className="fs-12 text-muted">IVA das vendas {eur(r.vat.sales_vat_cents)} − IVA das compras {eur(r.vat.purchases_vat_cents)}</div>
                        <div className="fs-12 text-muted">{r.vat.note}{r.vat.docs_without_vat > 0 && <> {r.vat.docs_without_vat} documentos ainda sem o IVA lido não contam.</>}</div>
                    </div>
                </Col>
            </Row>
            {r.out_of_result.length > 0 && (
                <div className="mb-3" data-testid="result-out">
                    <div className="fs-12 text-muted text-uppercase fw-semibold mb-1">Fora do resultado</div>
                    <ul className="fs-13 mb-0 ps-3">
                        {r.out_of_result.map((o) => <li key={o.label}>{o.label}: {eur(o.cents)} <span className="text-muted">({o.reason})</span></li>)}
                    </ul>
                </div>
            )}
        </div>
    );
}
