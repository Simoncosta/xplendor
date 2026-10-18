import { useEffect, useMemo, useState } from "react";
import ReactApexChart from "react-apexcharts";
import { Badge, Button, Modal, ModalBody, ModalFooter, ModalHeader, Table } from "reactstrap";
import Skeleton from "Components/Common/Skeleton";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { getPriceHistory } from "helpers/laravel_helper";
import { tokenColor } from "pages/Restauracao/menu/menuFormat";

/**
 * XPLENDOR — Auditoria v2-C (C1): "Ver histórico de preços" (o preço por unidade base de cada compra,
 * uma linha por fornecedor, 12 meses) e "Comparar fornecedores" (o último preço, a mediana e a
 * quantidade de 90 dias de cada um). Só leitura: nunca muda o fornecedor nem o preço.
 */
type Point = { date: string; price: number; qty: number; document: string; docheader_id: string; confirmed: boolean };
type Supplier = {
    key: string; product_id: string; supplier: string; points: Point[]; last_price: number; last_date: string; last_document: string;
    median_90: number | null; qty_90: number; purchases_90: number; confirmed: boolean;
};
type History = { articles: { product_id: string; label: string; base_unit: string }[]; suppliers: Supplier[]; financial: { visible: boolean } };

const eur = (v: number | null) => (v === null ? "—" : `${v.toLocaleString("pt-PT", { minimumFractionDigits: v < 0.1 ? 4 : 2, maximumFractionDigits: v < 0.1 ? 4 : 2 })} €`);
const qty = (v: number) => v.toLocaleString("pt-PT", { maximumFractionDigits: 3 });
const day = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT");
const COLORS = ["--vz-primary", "--vz-success", "--vz-warning", "--vz-info", "--vz-danger", "--vz-secondary"];
const SHORT: Record<string, string> = { quilograma: "kg", litro: "L", unidade: "un" };
// As datas do gráfico em português.
const PT = {
    name: "pt",
    options: {
        months: ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"],
        shortMonths: ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"],
        days: ["domingo", "segunda", "terça", "quarta", "quinta", "sexta", "sábado"],
        shortDays: ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"],
    },
};

export default function PriceHistoryModal({ companyId, productId, products, tab: initialTab = "historico", onClose }: {
    companyId: number; productId: string; products?: string[]; tab?: "historico" | "comparar"; onClose: () => void;
}) {
    const [data, setData] = useState<History | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [tab, setTab] = useState(initialTab);
    const others = (products ?? []).filter((p) => p !== productId);
    const key = others.join(",");

    useEffect(() => {
        let alive = true;
        getPriceHistory(companyId, productId, key ? key.split(",") : undefined)
            .then((res: any) => { if (alive) setData(res?.data ?? null); })
            .catch((e: any) => { if (alive) setError(e?.message ?? "Não foi possível ler o histórico de preços."); });
        return () => { alive = false; };
    }, [companyId, productId, key]);

    const article = data?.articles.find((a) => a.product_id === productId);
    const unit = article?.base_unit ?? "unidade";
    const short = SHORT[unit] ?? unit;
    const many = (data?.articles.length ?? 0) > 1;
    const label = (s: Supplier) => (many ? `${s.supplier} (${data?.articles.find((a) => a.product_id === s.product_id)?.label ?? s.product_id})` : s.supplier);
    const chart = useMemo(() => ({
        series: (data?.suppliers ?? []).map((s) => ({ name: label(s), data: s.points.map((p) => ({ x: new Date(`${p.date}T00:00:00`).getTime(), y: p.price })) })),
        options: {
            chart: { toolbar: { show: false }, animations: { enabled: false }, zoom: { enabled: false }, locales: [PT], defaultLocale: "pt" },
            colors: COLORS.map((c) => tokenColor(`var(${c})`)),
            stroke: { width: 2, curve: "straight" as const },
            markers: { size: 4 },
            xaxis: { type: "datetime" as const, labels: { datetimeUTC: false } },
            yaxis: { labels: { formatter: (v: number) => eur(v) } },
            tooltip: { x: { format: "dd/MM/yyyy" }, y: { formatter: (v: number) => `${eur(v)} por ${unit}` } },
            legend: { position: "bottom" as const },
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), [data, unit]);
    // "Mais barato" só quando há com quem comparar: dois ou mais fornecedores com compras nos 90 dias.
    const recent = data?.suppliers.filter((s) => s.median_90 !== null) ?? [];
    const best = recent.length > 1 ? recent.reduce<Supplier | null>((b, s) => (!b || s.last_price < b.last_price ? s : b), null) : null;

    return (
        <Modal isOpen toggle={onClose} size="xl" fullscreen="sm" scrollable centered data-testid="price-history">
            <ModalHeader toggle={onClose}>
                {tab === "historico" ? "Histórico de preços" : "Comparar fornecedores"}
                {article && <div className="fs-13 text-muted fw-normal">{article.label} · preço por {unit}</div>}
            </ModalHeader>
            <ModalBody>
                <div className="xp-seg mb-3" role="tablist" aria-label="Vista">
                    <button type="button" role="tab" aria-selected={tab === "historico"} className={tab === "historico" ? "on" : ""} onClick={() => setTab("historico")}>Histórico</button>
                    <button type="button" role="tab" aria-selected={tab === "comparar"} className={tab === "comparar" ? "on" : ""} onClick={() => setTab("comparar")}>Comparar fornecedores</button>
                </div>
                {error ? <p className="text-danger mb-0">{error}</p> : !data ? <Skeleton variant="lines" lines={6} label="A ler as compras…" /> : !data.financial.visible ? (
                    <FinancialNote />
                ) : data.suppliers.length === 0 ? (
                    <p className="text-muted mb-0">Sem compras nos últimos 12 meses.</p>
                ) : tab === "historico" ? (
                    <>
                        <ReactApexChart type="line" height={320} series={chart.series as any} options={chart.options as any} />
                        <p className="text-muted fs-12 mb-0">Cada ponto é uma compra (fatura fechada), em € por {unit}. Os preços «a confirmar» usam a quantidade do PingWin, sem a conversão da unidade de compra confirmada.</p>
                    </>
                ) : (
                    <div className="table-responsive">
                        <Table size="sm" className="align-middle mb-0 fs-13" data-testid="price-compare">
                            <thead className="table-light">
                                <tr><th>Fornecedor</th><th className="text-end">Último preço</th><th>Última compra</th><th className="text-end">Mediana 90 dias</th><th className="text-end">Comprado em 90 dias</th><th>Quantidade</th></tr>
                            </thead>
                            <tbody>
                                {data.suppliers.map((s) => (
                                    <tr key={s.key}>
                                        <td className="fw-medium">{label(s)}{best?.key === s.key && <Badge color="success" className="ms-1 bg-success-subtle text-success">mais barato</Badge>}</td>
                                        <td className="text-end text-nowrap">{eur(s.last_price)}</td>
                                        <td className="text-nowrap">{day(s.last_date)} · {s.last_document}</td>
                                        <td className="text-end text-nowrap">{eur(s.median_90)}</td>
                                        <td className="text-end text-nowrap">{s.purchases_90 ? `${qty(s.qty_90)} ${short} (${s.purchases_90} ${s.purchases_90 === 1 ? "compra" : "compras"})` : "—"}</td>
                                        <td>{s.confirmed ? <span className="text-success">Confirmada</span> : <span className="text-warning">A confirmar</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <span className="text-muted fs-12 me-auto">Só leitura: para mudar de fornecedor ou combinar o preço, fale com o fornecedor.</span>
                <Button type="button" color="light" onClick={onClose}>Fechar</Button>
            </ModalFooter>
        </Modal>
    );
}
