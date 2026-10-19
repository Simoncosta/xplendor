import { useCallback, useEffect, useMemo, useState } from "react";
import { Alert, Badge, Button, Container, Input, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import InfoTip from "Components/Common/InfoTip";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { getPurchaseSuggestions } from "helpers/laravel_helper";
import { PurchaseSuggestions, SupplierSuggestion } from "common/models/purchaseSuggestions.model";

/**
 * XPLENDOR — Restauração › Compras sugeridas do Economato (só leitura: nada se encomenda no PingWin).
 * Por fornecedor, a data provável da próxima entrega e as linhas sugeridas (a mediana semanal das
 * compras, ajustada pela previsão e descontado o stock contado), com as quantidades editáveis, a
 * exportação (CSV e impressão) e "Copiar texto" para mandar ao fornecedor. O erro medido das
 * últimas 4 semanas está sempre à vista.
 */
const WEEKDAY = ["", "segunda-feira", "terça-feira", "quarta-feira", "quinta-feira", "sexta-feira", "sábado", "domingo"];
const WEEKDAY_PL = ["", "segundas", "terças", "quartas", "quintas", "sextas", "sábados", "domingos"];
const day = (d: string) => d.split("-").reverse().join("/");
const longDay = (d: string) => `${WEEKDAY[((new Date(`${d}T00:00:00`).getDay() + 6) % 7) + 1]}, ${day(d).slice(0, 5)}`;
// Com o separador dos milhares sempre ("1 041,41 €"; o pt-PT do browser não agrupa 4 dígitos).
const eur = (c: number | null) => {
    if (c === null) return "—";
    const [i, d] = (Math.abs(c) / 100).toFixed(2).split(".");
    return `${c < 0 ? "−" : ""}${i.replace(/\B(?=(\d{3})+(?!\d))/g, " ")},${d} €`;
};
const qty = (n: number) => n.toLocaleString("pt-PT", { maximumFractionDigits: 2 });
const list = (xs: string[]) => (xs.length <= 1 ? xs.join("") : `${xs.slice(0, -1).join(", ")} e ${xs[xs.length - 1]}`);
const rhythm = (s: SupplierSuggestion) => [
    s.usual_days.length ? `costuma faturar às ${list(s.usual_days.map((d) => WEEKDAY_PL[d]))}` : null,
    `uma fatura a cada ${s.interval_days.toLocaleString("pt-PT", { maximumFractionDigits: 1 })} dias, em média`,
].filter(Boolean).join("; ");

export default function ComprasSugeridasPage() {
    document.title = "Compras sugeridas | Restauração | Xplendor";
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<PurchaseSuggestions | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [edits, setEdits] = useState<Record<string, number>>({});

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const res: any = await getPurchaseSuggestions(companyId);
            setData(res?.data ?? null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível calcular as compras sugeridas.");
        }
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    const hidden = data?.financial?.visible === false;
    const keyOf = (s: SupplierSuggestion, pid: string) => `${s.key}|${pid}`;
    const qtyOf = useCallback((s: SupplierSuggestion, pid: string, base: number) => edits[keyOf(s, pid)] ?? base, [edits]);
    const lineTotal = (s: SupplierSuggestion, l: SupplierSuggestion["lines"][number]) => (l.price_cents === null ? null : Math.round(qtyOf(s, l.product_id, l.suggested) * l.price_cents));
    const supplierTotal = (s: SupplierSuggestion) => (hidden ? null : s.lines.reduce((t, l) => t + (lineTotal(s, l) ?? 0), 0));
    const holidays = useMemo(() => Object.entries(data?.forecast.holidays ?? {}), [data]);

    const text = (s: SupplierSuggestion) => [
        "Bom dia,",
        `Para a entrega de ${longDay(s.next_delivery)}, pedimos:`,
        ...s.lines.filter((l) => qtyOf(s, l.product_id, l.suggested) > 0).map((l) => `- ${qty(qtyOf(s, l.product_id, l.suggested))} ${l.unit} de ${l.description}`),
        "Obrigado.",
    ].join("\n");
    const copy = async (s: SupplierSuggestion) => {
        try {
            await navigator.clipboard.writeText(text(s));
            toast.success("Texto copiado: cole-o no WhatsApp ou no email do fornecedor.");
        } catch {
            toast.error("Não foi possível copiar. Selecione e copie o texto à mão.");
        }
    };
    const csv = () => {
        if (!data) return;
        const esc = (v: string | number | null) => `"${String(v ?? "").replace(/"/g, '""')}"`;
        const rows = [["Fornecedor", "Entrega provável", "Código", "Artigo", "Sugerido", "Unidade", "Última compra", "Preço", "Total"].map(esc).join(";")];
        data.suppliers.forEach((s) => s.lines.forEach((l) => rows.push([s.name, day(s.next_delivery), l.code ?? "", l.description, qty(qtyOf(s, l.product_id, l.suggested)), l.unit,
            `${day(l.last_purchase.date)} ${qty(l.last_purchase.qty)} ${l.last_purchase.unit}`, hidden || l.price_cents === null ? "" : (l.price_cents / 100).toFixed(2).replace(".", ","),
            hidden ? "" : ((lineTotal(s, l) ?? 0) / 100).toFixed(2).replace(".", ",")].map(esc).join(";"))));
        const blob = new Blob(["﻿" + rows.join("\n")], { type: "text/csv;charset=utf-8" });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = `compras-sugeridas-${data.today}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    };

    return (
        <div className="page-content xp-print-area">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Compras sugeridas" breadcrumbs={[{ label: "Restauração" }]}
                    info="Uma sugestão do que encomendar a cada fornecedor do Economato: a mediana semanal das compras das últimas 8 semanas (sem compras únicas nem semanas fora do normal), ajustada pela previsão da faturação e descontado o stock contado. Só leitura: nada se encomenda no PingWin."
                    filters={data ? <div className="d-flex gap-2 d-print-none">
                        <Button size="sm" color="outline-primary" onClick={csv} data-testid="suggestions-csv"><i className="ri-download-2-line me-1" aria-hidden />Exportar CSV</Button>
                        <Button size="sm" color="outline-primary" onClick={() => window.print()}><i className="ri-printer-line me-1" aria-hidden />Imprimir</Button>
                    </div> : undefined} />
                {hidden && <FinancialNote className="mb-3" />}
                {error && !data ? <p className="text-danger">{error}</p> : !data ? <Skeleton variant="lines" lines={8} label="A calcular as sugestões…" /> : (
                    <>
                        <div className="card card-body py-2 px-3 mb-3 d-flex flex-row flex-wrap align-items-center gap-2" data-testid="suggestions-error">
                            <span className="fw-semibold fs-13">Erro medido: {data.error.pct === null ? "ainda sem semanas para medir" : `${data.error.pct.toLocaleString("pt-PT", { maximumFractionDigits: 0 })} %`} (últimas 4 semanas)</span>
                            <InfoTip label="Sobre o erro medido" text={
                                <div className="text-start">
                                    <div className="mb-1">Em cada semana, a sugestão dessa semana (sem o ajuste nem o stock) comparada com o que se comprou de facto: a soma das diferenças, em € ao último preço, a dividir pelo que se comprou.</div>
                                    {data.error.weeks.map((w) => <div key={w.week}>Semana de {day(w.week).slice(0, 5)}: {w.error_pct === null ? (w.note ?? "sem compras") : `${w.error_pct.toLocaleString("pt-PT", { maximumFractionDigits: 0 })} %`}</div>)}
                                </div>
                            } />
                        </div>
                        <Alert color="info" className="fs-13 py-2" data-testid="suggestions-forecast">{data.forecast.note}</Alert>
                        {holidays.length > 0 && <Alert color="warning" className="fs-13 py-2" data-testid="suggestions-holidays"><i className="ri-flag-line me-1" aria-hidden />Semana com feriado: {holidays.map(([d, n]) => `${n} (${day(d).slice(0, 5)})`).join(", ")}. A procura pode mudar.</Alert>}
                        {!data.stock
                            ? <Alert color="warning" className="fs-13 py-2" data-testid="suggestions-no-stock">Sem stock contado no Economato nos últimos 14 dias: a sugestão não desconta o que já existe.</Alert>
                            : <Alert color="success" className="fs-13 py-2">Descontado o stock da contagem de {day(data.stock.date)} ({data.stock.articles} artigos).</Alert>}
                        {data.suppliers.length === 0 && <PageCard title="Sem sugestões" flush={false}><p className="text-muted mb-0">Não há compras regulares com a unidade confirmada nas últimas 8 semanas.</p></PageCard>}
                        {data.suppliers.map((s) => (
                            <PageCard key={s.key} flush title={s.name} data-testid={`supplier-${s.key}`}
                                status={<>Próxima entrega provável: <strong>{longDay(s.next_delivery)}</strong> · {rhythm(s)}{!hidden && <> · {eur(supplierTotal(s))}</>}</>}
                                actions={<Button size="sm" color="outline-primary" className="d-print-none" onClick={() => copy(s)} data-testid="supplier-copy"><i className="ri-file-copy-line me-1" aria-hidden />Copiar texto</Button>}>
                                <div className="table-responsive">
                                    <Table size="sm" className="align-middle mb-0 fs-13">
                                        <thead className="table-light">
                                            <tr><th>Artigo</th><th className="text-end" style={{ width: 120 }}>Sugerido</th><th>Unidade</th><th>Última compra</th><th className="text-end">Preço</th><th className="text-end">€ total</th></tr>
                                        </thead>
                                        <tbody>
                                            {s.lines.map((l) => (
                                                <tr key={l.product_id}>
                                                    <td>{l.description}{l.code && <div className="text-muted fs-12">{l.code}</div>}</td>
                                                    <td className="text-end">
                                                        <Input type="number" bsSize="sm" min={0} step="any" className="text-end" aria-label={`Quantidade de ${l.description}`}
                                                            value={qtyOf(s, l.product_id, l.suggested)} onChange={(e) => setEdits((x) => ({ ...x, [keyOf(s, l.product_id)]: Math.max(0, Number(e.target.value) || 0) }))} />
                                                    </td>
                                                    <td className="text-nowrap">{l.unit}{!l.pack_confirmed && <Badge color="light" className="ms-1 text-muted" title="Sem a embalagem de compra confirmada no Guião de unidades: a sugestão fica na unidade base.">sem embalagem confirmada</Badge>}</td>
                                                    <td className="text-nowrap">{day(l.last_purchase.date)} · {qty(l.last_purchase.qty)} {l.last_purchase.unit}</td>
                                                    <td className="text-end text-nowrap">{hidden ? "—" : eur(l.price_cents)}</td>
                                                    <td className="text-end text-nowrap fw-medium">{hidden ? "—" : eur(lineTotal(s, l))}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </Table>
                                </div>
                            </PageCard>
                        ))}
                    </>
                )}
            </Container>
        </div>
    );
}
