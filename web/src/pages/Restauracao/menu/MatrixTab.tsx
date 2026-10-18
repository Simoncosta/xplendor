import { useEffect, useMemo, useState } from "react";
import ReactApexChart from "react-apexcharts";
import { Alert, Badge, Col, ListGroup, ListGroupItem, Row } from "reactstrap";
import XSelect from "Components/Common/Select";
import InfoTip from "Components/Common/InfoTip";
import { FamilyStats, MenuClass, MenuItem } from "common/models/menuEngineering.model";
import { CLASS_META, CLASS_ORDER, CONFIDENCE_META, byClass, costLabel, familiesByRevenue, fmtEur, fmtPct, fmtQty, matrixPoints } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: a matriz de Kasavana e Smith de uma família. x = quota da quantidade na família,
 * y = margem unitária; as duas linhas (popularidade e margem média); bolha = receita; cor = classe;
 * marcador vazio = Parcial (custo mínimo). Os artigos sem custo ficam numa faixa à parte. No telemóvel
 * (< 768 px) é uma lista por classe. Clicar num artigo abre o detalhe.
 */
const MOBILE = "(max-width: 767.98px)";

export function useIsMobile() {
    const [m, setM] = useState(() => typeof window !== "undefined" && !!window.matchMedia && window.matchMedia(MOBILE).matches);
    useEffect(() => {
        if (!window.matchMedia) return;
        const mq = window.matchMedia(MOBILE);
        const on = () => setM(mq.matches);
        mq.addEventListener?.("change", on);
        return () => mq.removeEventListener?.("change", on);
    }, []);
    return m;
}

const cssVar = (name: string, fallback: string) => {
    try {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    } catch {
        return fallback;
    }
};

export function ItemLine({ item, hidden, onOpen }: { item: MenuItem; hidden: boolean; onOpen: (i: MenuItem) => void }) {
    return (
        <ListGroupItem action tag="button" type="button" onClick={() => onOpen(item)} className="d-flex justify-content-between align-items-start gap-2" data-testid="menu-item-line">
            <div className="text-start">
                <div className="fw-medium">{item.description}</div>
                <div className="text-muted fs-12">
                    {fmtQty(item.qty)} vendidos · quota {fmtPct(item.share_pct)}
                    {!hidden && item.margin_eur !== null && <> · margem {fmtEur(item.margin_eur)}</>}
                </div>
            </div>
            <div className="text-end">
                <Badge color={CONFIDENCE_META[item.confidence].color} className="fs-11">{CONFIDENCE_META[item.confidence].label}</Badge>
                {item.fragile && <div className="fs-11 text-warning-emphasis mt-1"><i className="ri-error-warning-line me-1" />Classe frágil</div>}
            </div>
        </ListGroupItem>
    );
}

export default function MatrixTab({ items, families, hidden, onOpen }: { items: MenuItem[]; families: FamilyStats[]; hidden: boolean; onOpen: (i: MenuItem) => void }) {
    const mobile = useIsMobile();
    const familyNames = useMemo(() => familiesByRevenue(items), [items]);
    const [family, setFamily] = useState<string>("");
    useEffect(() => {
        if (!family || !familyNames.includes(family)) setFamily(familyNames[0] ?? "");
    }, [familyNames, family]);
    const stats = families.find((f) => f.family === family) ?? null;
    const { costed, noCost } = useMemo(() => matrixPoints(items, family), [items, family]);
    const groups = useMemo(() => byClass(items.filter((i) => i.family === family)), [items, family]);

    const chart = useMemo(() => {
        const maxRev = Math.max(1, ...costed.map((i) => i.revenue_eur ?? i.qty));
        const size = (i: MenuItem) => 6 + 18 * Math.sqrt((i.revenue_eur ?? i.qty) / maxRev);
        // Uma série por classe e confiança: Confirmado cheio, Parcial vazio (contorno na cor da classe).
        const groups = CLASS_ORDER.flatMap((c) => (["confirmado", "parcial"] as const).map((conf) => ({ c, conf })));
        const series = groups.map(({ c, conf }) => ({
            name: conf === "parcial" ? `${CLASS_META[c].label} (custo parcial)` : CLASS_META[c].label,
            data: costed.filter((i) => i.class === c && i.confidence === conf).map((i) => ({ x: i.share_pct ?? 0, y: i.margin_eur ?? 0, item: i })),
        }));
        const hexes = groups.map(({ c }) => CLASS_META[c].hex);
        const discrete: any[] = [];
        // O interior do marcador vazio é o fundo do cartão (lido do elemento: certo no claro e no escuro).
        const card = typeof document !== "undefined" ? document.querySelector("[data-testid=menu-matrix]")?.closest(".card") : null;
        const bg = (card && getComputedStyle(card).backgroundColor) || cssVar("--vz-card-bg", "#fff");
        series.forEach((s, si) => s.data.forEach((p, di) => discrete.push({ seriesIndex: si, dataPointIndex: di, size: size(p.item),
            fillColor: groups[si].conf === "parcial" ? bg : hexes[si], strokeColor: hexes[si] })));
        const fg = cssVar("--vz-body-color", "#495057");
        const grid = cssVar("--vz-border-color", "#e9ebec");
        return {
            series,
            options: {
                chart: { type: "scatter", toolbar: { show: false }, zoom: { enabled: false }, foreColor: fg, animations: { enabled: false },
                    events: { markerClick: (_e: any, _c: any, o: any) => { const p = series[o.seriesIndex]?.data[o.dataPointIndex]; if (p) onOpen(p.item); } } },
                colors: hexes,
                markers: { size: 8, strokeWidth: 2, strokeColors: hexes, fillOpacity: 1, discrete, hover: { sizeOffset: 2 } },
                grid: { borderColor: grid },
                legend: { position: "bottom", customLegendItems: [...CLASS_ORDER.map((c) => CLASS_META[c].label), "Marcador vazio: custo parcial"],
                    markers: { fillColors: [...CLASS_ORDER.map((c) => CLASS_META[c].hex), "transparent"] } },
                xaxis: { type: "numeric", min: 0, max: Math.max(10, Math.ceil(Math.max(...costed.map((i) => i.share_pct ?? 0), stats?.popularity_threshold_pct ?? 0) * 1.12)), tickAmount: 5, title: { text: "Quota da quantidade na família (%)" }, labels: { formatter: (v: number) => `${Math.round(v)}%` } },
                yaxis: { title: { text: "Margem unitária (€)" }, labels: { formatter: (v: number) => fmtEur(v) } },
                annotations: {
                    xaxis: stats?.popularity_threshold_pct != null ? [{ x: stats.popularity_threshold_pct, borderColor: fg, strokeDashArray: 4,
                        label: { text: `Limiar ${fmtPct(stats.popularity_threshold_pct)}`, style: { background: cssVar("--vz-card-bg", "#fff"), color: fg } } }] : [],
                    yaxis: stats?.margin_line != null ? [{ y: stats.margin_line, borderColor: fg, strokeDashArray: 4,
                        label: { text: `Margem média ${fmtEur(stats.margin_line)}`, style: { background: cssVar("--vz-card-bg", "#fff"), color: fg } } }] : [],
                },
                tooltip: {
                    custom: ({ seriesIndex, dataPointIndex }: any) => {
                        const p = series[seriesIndex]?.data[dataPointIndex]?.item as MenuItem | undefined;
                        if (!p) return "";
                        return `<div class="p-2"><strong>${p.description}</strong><br/>Quota ${fmtPct(p.share_pct)} · ${fmtQty(p.qty)} vendidos<br/>`
                            + `Preço ${fmtEur(p.price_eur)} · custo ${costLabel(p)}<br/>Margem ${fmtEur(p.margin_eur)} · ${CONFIDENCE_META[p.confidence].label}</div>`;
                    },
                },
            },
        };
    }, [costed, stats, onOpen]);

    return (
        <div data-testid="menu-matrix">
            <Row className="g-2 align-items-end mb-2">
                <Col xs={12} md={5}>
                    <label className="form-label mb-1">Família</label>
                    <XSelect<string> value={family} ariaLabel="Família da matriz" options={familyNames.map((f) => ({ value: f, label: f }))} onChange={(v) => setFamily(v)} />
                </Col>
                {stats && (
                    <Col xs={12} md={7} className="fs-13 text-muted">
                        {stats.items} artigos na matriz ({stats.costed} com custo) · limiar da popularidade {fmtPct(stats.popularity_threshold_pct)}
                        <InfoTip label="Limiar da popularidade" text="Um artigo é muito vendido quando a sua quota na quantidade da família chega a 70 % de 1 ÷ N, sendo N o número de artigos da família na matriz (Kasavana e Smith)." />
                        {!hidden && <> · margem média {fmtEur(stats.margin_line)}<InfoTip label="Margem média" text="A margem unitária média da família, pesada pela quantidade vendida, só com os artigos que têm custo (Confirmado ou Parcial)." /></>}
                    </Col>
                )}
            </Row>
            {stats && stats.fragile.length > 0 && (
                <Alert color="warning" className="py-2 fs-13 mb-2" data-testid="menu-fragile">
                    <i className="ri-error-warning-line me-1" />Classes frágeis nesta família: {stats.fragile.map((f) => f.text).join(" ")}
                </Alert>
            )}
            {hidden ? (
                <Alert color="light" className="fs-13">As margens só se mostram a quem vê as Finanças. A popularidade e as classes estão na lista abaixo.</Alert>
            ) : mobile ? null : costed.length ? (
                <div className="xp-menu-matrix"><ReactApexChart type="scatter" height={420} series={chart.series as any} options={chart.options as any} /></div>
            ) : (
                <Alert color="light" className="fs-13">Nenhum artigo desta família tem custo: a matriz precisa de fichas técnicas.</Alert>
            )}
            {(mobile || hidden) && (
                <div data-testid="menu-matrix-list">
                    {CLASS_ORDER.map((c: MenuClass) => groups[c].length > 0 && (
                        <div key={c} className="mb-3">
                            <h6 className="mb-1"><i className={`${CLASS_META[c].icon} text-${CLASS_META[c].color} me-1`} />{CLASS_META[c].label} ({groups[c].length})</h6>
                            <ListGroup>{groups[c].map((i) => <ItemLine key={i.product_id} item={i} hidden={hidden} onOpen={onOpen} />)}</ListGroup>
                        </div>
                    ))}
                </div>
            )}
            {noCost.length > 0 && (
                <div className="mt-2" data-testid="menu-nocost-band">
                    <div className="fs-13 fw-medium mb-1">Sem custo <span className="text-muted fw-normal">(só no eixo da popularidade: falta a ficha técnica)</span></div>
                    <div className="d-flex flex-wrap gap-1">
                        {noCost.sort((a, b) => (b.share_pct ?? 0) - (a.share_pct ?? 0)).map((i) => (
                            <button key={i.product_id} type="button" className="btn btn-sm btn-light" onClick={() => onOpen(i)}>
                                {i.description} · {fmtPct(i.share_pct)}{i.popular ? " · muito vendido" : ""}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
