import { useMemo, useRef, useState } from "react";
import ReactApexChart from "react-apexcharts";
import { Alert, Button, Card, CardBody, Col, Row, Table } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import { FinanceMonth, ResultRow } from "common/models/financeMonth.model";
import HabituaisSection from "./HabituaisSection";
import { compare, eur0, GROUP_COLORS, GROUP_NAMES, monthShort, pct, prevMonthName, ucfirst } from "./format";

/**
 * XPLENDOR — Finanças › Mês › Resumo (a primeira camada): o estado do mês (provisório ou fechado), os
 * quatro números grandes com o mês anterior, para onde foi o dinheiro (por grupo) e os últimos 6 meses.
 * Valores em euros sem cêntimos; cada grupo tem sempre a mesma cor; o vermelho fica para o vencido e
 * para o resultado negativo. Um "?" em cada bloco diz de onde vem o número. printable = sem gráficos,
 * com tabelas (o fecho do mês).
 */
export type DetailKind = "resultado" | "dinheiro" | "documentos" | `grupo-${string}`;

const lc = (s: string | null) => (s ? s.charAt(0).toLowerCase() + s.slice(1) : null);

function Tip({ label, text }: { label: string; text: string }) {
    return <InfoTip label={`De onde vem: ${label}`} text={text} icon="ri-question-line" />;
}

function BigCard({ title, subtitle, tip, testid, children, action }: {
    title: string; subtitle?: string; tip: string; testid: string; children: React.ReactNode; action?: React.ReactNode;
}) {
    return (
        <Card className="h-100 mb-0" data-testid={testid}>
            <CardBody className="d-flex flex-column" style={{ padding: "1.25rem" }}>
                <div className="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div>
                        <div className="fw-semibold fs-14">{title}</div>
                        {subtitle && <div className="fs-12 text-muted">{subtitle}</div>}
                    </div>
                    <Tip label={title} text={tip} />
                </div>
                <div className="flex-grow-1">{children}</div>
                {action && <div className="mt-2">{action}</div>}
            </CardBody>
        </Card>
    );
}

/** Os grupos do mês (os cabeçalhos de grupo do resultado), na ordem do resultado. */
export function monthGroups(rows: ResultRow[]): ResultRow[] {
    return rows.filter((r) => r.kind === "grupo" && r.group && r.group !== "fora" && (r.cents !== 0 || r.group !== "por_classificar"));
}

/**
 * A frase do topo, com os números dos cartões e da lista dos habituais (sem cálculos novos). Num mês
 * completo: "Em setembro de 2026 vendeu X €, os custos foram Y € e o resultado é Z €." Num mês
 * provisório, mostra o passo que o estimado dá, para a conta bater: "Em setembro de 2026 vendeu X €.
 * Os custos lançados são Y €, faltam cerca de M € em documentos habituais e o resultado estimado é Z €."
 */
export function summarySentence(data: FinanceMonth, storeName: string | null): string {
    const c = data.cards;
    const estimated = c.result.estimated_cents !== null && c.result.estimated_cents !== undefined && !!data.provisional;
    const result = estimated ? (c.result.estimated_cents as number) : c.result.cents;
    const value = `${result < 0 ? "negativo, " : ""}${eur0(result)}`;
    const sold = storeName ? `Em ${data.month_label}, ${storeName} vendeu ${eur0(c.sales.store_cents)}` : `Em ${data.month_label} vendeu ${eur0(c.sales.cents)}`;
    if (estimated) {
        const costs = `${storeName ? "Na empresa toda, os custos lançados" : "Os custos lançados"} são ${eur0(c.expenses.cents)}`;
        return `${sold}. ${costs}, faltam cerca de ${eur0(data.provisional!.cents)} em documentos habituais e o resultado estimado é ${value}.`;
    }
    return storeName
        ? `${sold}; na empresa toda, os custos foram ${eur0(c.expenses.cents)} e o resultado é ${value}.`
        : `${sold}, os custos foram ${eur0(c.expenses.cents)} e o resultado é ${value}.`;
}

export default function ResumoTab({ data, storeName, canEdit = false, printable = false, onDetail, onSeeMissing }: {
    data: FinanceMonth; storeName: string | null; canEdit?: boolean; printable?: boolean;
    onDetail?: (kind: DetailKind) => void; onSeeMissing?: () => void;
}) {
    const c = data.cards;
    const prov = data.provisional;
    const prevName = prevMonthName(data.month);
    const [showMissing, setShowMissing] = useState(printable);
    // O clique no donut usa sempre o onDetail atual, sem refazer o gráfico a cada render.
    const detail = useRef(onDetail);
    detail.current = onDetail;
    const groups = useMemo(() => monthGroups(data.result.rows), [data.result.rows]);
    const sales = storeName ? c.sales.store_cents : c.sales.cents;
    const estimated = c.result.estimated_cents !== null && c.result.estimated_cents !== undefined;
    const resultCents = estimated ? (c.result.estimated_cents as number) : c.result.cents;
    const resultPct = estimated ? c.result.estimated_pct : c.result.pct;
    const up = c.result.prev_pct !== null && c.result.pct !== null ? c.result.pct >= c.result.prev_pct : null;
    const inCents = c.cash.in_cents;
    const expensesPct = data.result.rows.find((r) => r.kind === "total")?.pct;
    const chartRows = data.result.chart;

    const donut = useMemo(() => {
        const slices = groups.filter((g) => g.cents > 0);
        return {
            series: slices.map((g) => g.cents / 100),
            options: {
                chart: { type: "donut", animations: { enabled: false },
                    events: { dataPointSelection: (_e: any, _c: any, o: any) => { const g = slices[o.dataPointIndex]; if (g) detail.current?.(`grupo-${g.group}`); } } },
                labels: slices.map((g) => GROUP_NAMES[g.group as string]?.short ?? g.label),
                colors: slices.map((g) => GROUP_COLORS[g.group as string] ?? "#b8c0c9"),
                legend: { show: false },
                dataLabels: { enabled: false },
                stroke: { width: 2 },
                tooltip: { y: { formatter: (v: number) => eur0(Math.round(v * 100)) } },
                plotOptions: { pie: { donut: { size: "70%", labels: { show: false } } } },
            },
        };
    }, [groups]);

    const chart = useMemo(() => {
        const light = (hex: string, on: boolean) => (on ? `${hex}66` : hex);
        // No telemóvel, só o mês no eixo (os provisórios ficam na linha por baixo do gráfico).
        const narrow = typeof window !== "undefined" && !!window.matchMedia?.("(max-width: 575.98px)").matches;
        const cats = chartRows.map((m) => (narrow ? monthShort(m.month).slice(0, 3) : m.provisional ? [monthShort(m.month), "provisório"] : monthShort(m.month)));
        const resultPctOf = (m: (typeof chartRows)[number]) => (m.sales_cents > 0 ? Math.round((m.result_cents / m.sales_cents) * 1000) / 10 : null);
        return {
            series: [
                { name: "Vendas", type: "column", data: chartRows.map((m, i) => ({ x: cats[i], y: Math.round(m.sales_cents / 100), fillColor: light("#405189", !!m.provisional) })) },
                { name: "Custos", type: "column", data: chartRows.map((m, i) => ({ x: cats[i], y: Math.round(m.expenses_cents / 100), fillColor: light("#9aa5b1", !!m.provisional) })) },
                { name: "Resultado (% das vendas)", type: "line", data: chartRows.map((m, i) => ({ x: cats[i], y: resultPctOf(m) })) },
            ],
            options: {
                chart: { toolbar: { show: false }, animations: { enabled: false } },
                colors: ["#405189", "#9aa5b1", "#0ab39c"],
                stroke: { width: [0, 0, 3] },
                markers: { size: [0, 0, 5], discrete: chartRows.flatMap((m, i) => {
                    const p = resultPctOf(m);
                    const neg = p !== null && p < 0;
                    return m.provisional || neg ? [{ seriesIndex: 2, dataPointIndex: i, size: 5, fillColor: neg ? "#f06548" : "#ffffff", strokeColor: neg ? "#f06548" : "#0ab39c" }] : [];
                }) },
                dataLabels: { enabled: false },
                plotOptions: { bar: { columnWidth: "55%", borderRadius: 3 } },
                xaxis: { type: "category", labels: { rotate: 0, hideOverlappingLabels: false, style: { fontSize: narrow ? "10px" : "12px" } } },
                yaxis: [
                    { seriesName: "Vendas", labels: { formatter: (v: number) => `${Math.round(v / 1000).toLocaleString("pt-PT")} mil €` } },
                    { seriesName: "Vendas", show: false },
                    { seriesName: "Resultado (% das vendas)", opposite: true, labels: { formatter: (v: number) => `${Math.round(v)} %` } },
                ],
                tooltip: { shared: true, y: { formatter: (v: number, o: any) => (o?.seriesIndex === 2 ? pct(v) : eur0(Math.round(v * 100))) } },
                legend: { position: "bottom" },
            },
        };
    }, [chartRows]);

    return (
        <div data-testid="finance-summary">
            <Card className="mb-3" data-testid="finance-status">
                <CardBody className="p-3">
                    <p className="fs-15 fw-medium mb-2" data-testid="finance-summary-sentence">{summarySentence(data, storeName)}</p>
                    <div className="d-flex flex-wrap align-items-center gap-2">
                        <span className="fs-16 fw-semibold">{ucfirst(data.month_label)}</span>
                        {prov ? (
                            <span className="d-flex flex-wrap align-items-center gap-2" data-testid="finance-provisional">
                                <span className="badge bg-warning-subtle text-warning fs-12">Provisório</span>
                                <span className="fs-13">Faltam {prov.count} {prov.count === 1 ? "documento habitual" : "documentos habituais"} (cerca de {eur0(prov.cents)}).</span>
                                {!printable && <Button size="sm" color="link" className="p-0" data-testid="see-missing"
                                    onClick={() => (canEdit && onSeeMissing ? onSeeMissing() : setShowMissing((v) => !v))}>
                                    {showMissing && !canEdit ? "Esconder" : "Ver quais"}
                                </Button>}
                            </span>
                        ) : (
                            <span className="d-flex flex-wrap align-items-center gap-1" data-testid="finance-complete">
                                <span className="badge bg-success-subtle text-success fs-12">Completo</span>
                                <Tip label="Completo" text="Não falta nenhum documento habitual." />
                            </span>
                        )}
                    </div>

                    {prov && showMissing && <div className="mt-2"><HabituaisSection data={data} editable={false} /></div>}
                    {data.warnings.length > 0 && (
                        <Alert color="warning" className="py-2 px-3 fs-12 mt-2 mb-0" data-testid="finance-warnings">
                            <ul className="mb-0 ps-3">{data.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                        </Alert>
                    )}
                    {storeName && <div className="fs-12 text-muted mt-2">{data.result.store_note}</div>}
                </CardBody>
            </Card>

            <Row className="g-3 mb-3" data-testid="finance-cards">
                <Col xs={12} md={6} xl={3}>
                    <BigCard testid="card-sales" title="Vendas" subtitle={storeName ? storeName : "Todos os restaurantes, sem IVA"}
                        tip={`${c.sales.source} ${c.sales.days} dos ${c.sales.days_in_month} dias com vendas.`}>
                        <div className="fs-2 fw-semibold lh-1 mb-2">{eur0(sales)}</div>
                        <div className="fs-13 text-muted">{storeName ? `A empresa toda vendeu ${eur0(c.sales.cents)}.` : compare(c.sales.cents, c.sales.prev_cents, prevName)}</div>
                    </BigCard>
                </Col>
                <Col xs={12} md={6} xl={3}>
                    <BigCard testid="card-expenses" title="Custos" subtitle="O que as faturas do mês custaram, sem IVA" tip={c.expenses.source}
                        action={!printable && <Button size="sm" color="link" className="p-0" onClick={() => onDetail?.("resultado")} data-testid="detail-resultado">Ver por família</Button>}>
                        <div className="fs-2 fw-semibold lh-1 mb-2">{eur0(c.expenses.cents)}</div>
                        <div className="fs-13 text-muted">{expensesPct !== null && expensesPct !== undefined && `${pct(expensesPct)} das vendas. `}{compare(c.expenses.cents, c.expenses.prev_cents, prevName)}</div>
                        {c.expenses.open_count > 0 && <div className="fs-12 text-muted mt-1" data-testid="card-expenses-open">Inclui {c.expenses.open_count} {c.expenses.open_count === 1 ? "documento em aberto" : "documentos em aberto"} ({eur0(c.expenses.open_cents)}).</div>}
                        {c.expenses.unclassified_cents !== 0 && <div className="fs-12 text-warning mt-1">Por classificar: {eur0(c.expenses.unclassified_cents)}.</div>}
                    </BigCard>
                </Col>
                <Col xs={12} md={6} xl={3}>
                    <BigCard testid="card-result" title="Resultado" subtitle="O que o mês ganhou: vendas menos custos"
                        tip={`${c.result.source}${estimated ? " O estimado já desconta os documentos habituais que ainda faltam." : ""}`}
                        action={!printable && <Button size="sm" color="link" className="p-0" onClick={() => onDetail?.("resultado")}>Ver o detalhe</Button>}>
                        <div className={`fs-2 fw-semibold lh-1 mb-2 ${resultCents < 0 ? "text-danger" : "text-success"}`} data-testid={estimated ? "card-result-estimated" : "card-result-real"}>
                            {eur0(resultCents)}{estimated && <span className="badge bg-warning-subtle text-warning fs-11 align-middle ms-2">estimado</span>}
                        </div>
                        <div className="fs-13 text-muted">{resultPct !== null && resultPct !== undefined ? `Por cada 100 € vendidos ficaram ${Math.round(resultPct).toLocaleString("pt-PT")} €.` : "Sem vendas para comparar."}</div>
                        {estimated && <div className="fs-12 text-muted">Real até agora: {eur0(c.result.cents)} ({pct(c.result.pct)}).</div>}
                        <div className="fs-12 mt-1" data-testid="card-result-prev">
                            {up !== null && <i className={`${up ? "ri-arrow-up-line text-success" : "ri-arrow-down-line text-danger"} me-1`} aria-hidden />}
                            {ucfirst(prevName)}: {eur0(c.result.prev_cents)} ({pct(c.result.prev_pct)} das vendas).
                        </div>
                    </BigCard>
                </Col>
                <Col xs={12} md={6} xl={3}>
                    <BigCard testid="card-cash" title="Dinheiro" subtitle="O que entrou e saiu da conta"
                        tip={`${c.cash.source} ${data.cash.entries_note} ${data.explain}`}
                        action={!printable && <Button size="sm" color="link" className="p-0" onClick={() => onDetail?.("dinheiro")} data-testid="detail-dinheiro">Ver o detalhe</Button>}>
                        {inCents !== undefined && <div className="d-flex justify-content-between fs-13"><span>Entrou</span><span className="fw-semibold">{eur0(inCents)}</span></div>}
                        <div className="d-flex justify-content-between fs-13"><span>Saiu</span><span className="fw-semibold">{eur0(c.cash.paid_cents)}</span></div>
                        <div className="d-flex justify-content-between fs-13"><span>Por pagar até ao fim do mês</span><span className="fw-semibold">{eur0(c.cash.due_by_month_end_cents)}</span></div>
                        <div className={`d-flex justify-content-between fs-13 ${c.cash.overdue_cents > 0 ? "text-danger" : ""}`}><span>Vencido</span><span className="fw-semibold">{eur0(c.cash.overdue_cents)}</span></div>
                        <div className="fs-12 text-muted mt-1">
                            {inCents !== undefined && (inCents >= c.cash.paid_cents ? `Entrou mais ${eur0(inCents - c.cash.paid_cents)} do que saiu. ` : `Saiu mais ${eur0(c.cash.paid_cents - inCents)} do que entrou. `)}
                            {c.cash.paid_prev_cents !== undefined && compare(c.cash.paid_cents, c.cash.paid_prev_cents, prevName) && `Saídas: ${lc(compare(c.cash.paid_cents, c.cash.paid_prev_cents, prevName))}`}
                        </div>
                    </BigCard>
                </Col>
            </Row>

            <Row className="g-3 mb-3">
                <Col xs={12} xl={5}>
                    <Card className="h-100 mb-0" data-testid="resumo-donut">
                        <CardBody style={{ padding: "1.25rem" }}>
                            <div className="d-flex align-items-start justify-content-between gap-2 mb-2">
                                <div>
                                    <div className="fw-semibold fs-14">Para onde foi o dinheiro</div>
                                    <div className="fs-12 text-muted">Os custos do mês por grupo{!printable && "; toque num grupo para ver o detalhe"}</div>
                                </div>
                                <Tip label="Para onde foi o dinheiro" text="Os custos do mês por grupo, pela data da fatura e sem IVA; a percentagem é sobre as vendas do mês." />
                            </div>
                            {/* No telemóvel o donut fica por cima da legenda. */}
                            <Row className="g-3 align-items-center">
                                {!printable && <Col xs={12} md={5} xl={12} xxl={5}>
                                    <div className="position-relative">
                                        <ReactApexChart type="donut" height={220} series={donut.series} options={donut.options as any} />
                                        {/* O total no centro (os custos do mês). */}
                                        <div className="position-absolute top-50 start-50 translate-middle text-center" style={{ pointerEvents: "none" }} data-testid="donut-total">
                                            <div className="fs-11 text-muted">Custos</div>
                                            <div className="fs-15 fw-semibold text-nowrap">{eur0(c.expenses.cents)}</div>
                                        </div>
                                    </div>
                                </Col>}
                                <Col xs={12} md={printable ? 12 : 7} xl={12} xxl={printable ? 12 : 7}>
                                    <ul className="list-unstyled mb-0" data-testid="donut-legend">
                                        {groups.map((g) => {
                                            const name = GROUP_NAMES[g.group as string];
                                            const inner = (
                                                <>
                                                    <span className="d-inline-block rounded-circle me-2 flex-shrink-0" style={{ width: 10, height: 10, background: GROUP_COLORS[g.group as string] ?? "#b8c0c9" }} aria-hidden />
                                                    <span className="flex-grow-1 text-start">{name?.short ?? g.label}{name?.hint && <span className="text-muted fs-11 d-block">{name.hint}</span>}</span>
                                                    <span className="fw-semibold text-nowrap ms-2">{eur0(g.cents)}</span>
                                                    <span className="text-muted text-nowrap ms-2 text-end" style={{ minWidth: 64 }}>{pct(g.pct)}</span>
                                                </>
                                            );
                                            return (
                                                <li key={g.group} className="border-bottom">
                                                    {printable ? <div className="d-flex align-items-center py-2 fs-13">{inner}</div> : (
                                                        <button type="button" className="btn btn-link text-body text-decoration-none d-flex align-items-center w-100 px-0 py-2 fs-13"
                                                            onClick={() => onDetail?.(`grupo-${g.group}`)} data-testid={`donut-group-${g.group}`} aria-label={`Ver o detalhe de ${g.label}`}>{inner}</button>
                                                    )}
                                                </li>
                                            );
                                        })}
                                        <li className="d-flex align-items-center py-2 fs-12 text-muted"><span className="flex-grow-1">Percentagem sobre as vendas</span></li>
                                    </ul>
                                </Col>
                            </Row>
                        </CardBody>
                    </Card>
                </Col>
                <Col xs={12} xl={7}>
                    <Card className="h-100 mb-0" data-testid="resumo-chart">
                        <CardBody style={{ padding: "1.25rem" }}>
                            <div className="d-flex align-items-start justify-content-between gap-2 mb-2">
                                <div>
                                    <div className="fw-semibold fs-14">Últimos 6 meses</div>
                                    <div className="fs-12 text-muted">Vendas e custos; a linha é o resultado em percentagem das vendas</div>
                                </div>
                                <Tip label="Últimos 6 meses" text="Vendas e custos de cada mês, sem IVA, e o resultado em percentagem das vendas; os meses mais claros ainda estão provisórios (faltam documentos habituais)." />
                            </div>
                            {printable ? (
                                <Table size="sm" className="fs-12 align-middle mb-0" data-testid="resumo-months">
                                    <thead className="table-light text-muted"><tr><th>Mês</th><th className="text-end">Vendas</th><th className="text-end">Custos</th><th className="text-end">Resultado</th><th className="text-end">% das vendas</th></tr></thead>
                                    <tbody>
                                        {chartRows.map((m) => (
                                            <tr key={m.month}>
                                                <td>{ucfirst(m.label)}{m.provisional && <span className="text-muted"> (provisório)</span>}</td>
                                                <td className="text-end text-nowrap">{eur0(m.sales_cents)}</td>
                                                <td className="text-end text-nowrap">{eur0(m.expenses_cents)}</td>
                                                <td className={`text-end text-nowrap ${m.result_cents < 0 ? "text-danger" : ""}`}>{eur0(m.result_cents)}</td>
                                                <td className="text-end">{pct(m.sales_cents > 0 ? Math.round((m.result_cents / m.sales_cents) * 1000) / 10 : null)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </Table>
                            ) : (
                                <ReactApexChart type="line" height={280} series={chart.series as any} options={chart.options as any} />
                            )}
                            {!printable && chartRows.some((m) => m.provisional) && (
                                <div className="fs-12 text-muted mt-1" data-testid="resumo-chart-provisional">
                                    Provisórios (mais claros): {chartRows.filter((m) => m.provisional).map((m) => monthShort(m.month)).join(", ")}.
                                </div>
                            )}
                        </CardBody>
                    </Card>
                </Col>
            </Row>
        </div>
    );
}
