import { useEffect, useMemo, useRef, useState } from "react";
import { MenuClass, MenuItem } from "common/models/menuEngineering.model";
import { CLASS_META, CONFIDENCE_META, costLabel, fmtEur, fmtPct, fmtQty, niceAxis, shortName } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: a matriz de Kasavana e Smith de uma família, em SVG. x = quota da quantidade na
 * família (%), y = margem unitária (€). Os quatro quadrantes têm um fundo ténue na cor da classe e o
 * nome no canto; as linhas da popularidade e da margem média têm o rótulo na horizontal, no fim. As
 * bolhas: tamanho = receita (ou quantidade, sem as Finanças); Confirmado com a cor cheia; Parcial com a
 * cor a 35 % e o contorno tracejado. O nome do prato vai ao lado (os que se sobrepõem ficam no tooltip).
 */
const DEFAULT_W = 900;
const H = 440;
const M = { l: 70, r: 28, t: 28, b: 46 };
const LABEL_CHARS = 18;
const CHAR_W = 6.4;

type Placed = { x: number; y: number; w: number; h: number };
const overlaps = (a: Placed, b: Placed) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

export default function MatrixChart({ items, threshold, line, onOpen }: { items: MenuItem[]; threshold: number | null; line: number | null; onOpen: (i: MenuItem) => void }) {
    const [hover, setHover] = useState<{ item: MenuItem; x: number; y: number } | null>(null);
    // A largura real do contentor: o texto fica sempre com 12 px, em qualquer ecrã.
    const box = useRef<HTMLDivElement>(null);
    const [W, setW] = useState(DEFAULT_W);
    useEffect(() => {
        const el = box.current;
        if (!el || typeof ResizeObserver === "undefined") return;
        const ro = new ResizeObserver(([e]) => setW(Math.max(560, Math.round(e.contentRect.width))));
        ro.observe(el);
        return () => ro.disconnect();
    }, []);
    const g = useMemo(() => {
        const xs = items.map((i) => i.share_pct ?? 0);
        const ys = items.map((i) => i.margin_eur ?? 0);
        const xa = niceAxis(0, Math.max(10, ...xs, threshold ?? 0) * 1.05, 6);
        const ya = niceAxis(Math.min(0, ...ys, line ?? 0), Math.max(1, ...ys, line ?? 0) * 1.05, 6);
        const sx = (v: number) => M.l + ((v - xa.min) / (xa.max - xa.min)) * (W - M.l - M.r);
        const sy = (v: number) => H - M.b - ((v - ya.min) / (ya.max - ya.min)) * (H - M.t - M.b);
        const maxRev = Math.max(1, ...items.map((i) => i.revenue_eur ?? i.qty));
        const bubbles = items.map((i) => ({ item: i, cx: sx(i.share_pct ?? 0), cy: sy(i.margin_eur ?? 0), r: 7 + 17 * Math.sqrt((i.revenue_eur ?? i.qty) / maxRev) }))
            .sort((a, b) => b.r - a.r);
        const tx = threshold !== null ? sx(Math.min(Math.max(threshold, xa.min), xa.max)) : null;
        const ty = line !== null ? sy(Math.min(Math.max(line, ya.min), ya.max)) : null;
        // Rótulos: pela receita; um rótulo que se sobreponha a outro já posto, a uma bolha ou ao rótulo
        // de um limiar fica de fora (aparece no tooltip).
        const placed: Placed[] = bubbles.map((b) => ({ x: b.cx - b.r, y: b.cy - b.r, w: b.r * 2, h: b.r * 2 }));
        const reserved: Placed[] = [];
        if (ty !== null) reserved.push({ x: W - M.r - 130, y: ty - 20, w: 130, h: 16 });
        if (tx !== null) reserved.push({ x: tx, y: M.t - 22, w: 130, h: 16 });
        const labels: { key: string; x: number; y: number; anchor: "start" | "end"; text: string }[] = [];
        for (const b of bubbles) {
            const text = shortName(b.item.description, LABEL_CHARS);
            const w = text.length * CHAR_W;
            const right = b.cx + b.r + 4 + w <= W - M.r;
            const box: Placed = { x: right ? b.cx + b.r + 4 : b.cx - b.r - 4 - w, y: b.cy - 7, w, h: 14 };
            const others = placed.filter((p) => !(p.x === b.cx - b.r && p.y === b.cy - b.r));
            if (others.some((p) => overlaps(p, box)) || reserved.some((p) => overlaps(p, box))) continue;
            placed.push(box);
            labels.push({ key: b.item.product_id, x: right ? box.x : box.x + w, y: b.cy + 4, anchor: right ? "start" : "end", text });
        }
        return { xa, ya, sx, sy, bubbles, labels, tx, ty };
    }, [items, threshold, line, W]);

    const x0 = M.l;
    const x1 = W - M.r;
    const y0 = M.t;
    const y1 = H - M.b;
    const quad = (c: MenuClass, x: number, y: number, w: number, h: number, lx: number, ly: number, anchor: "start" | "end") => (
        <g key={c}>
            <rect x={x} y={y} width={Math.max(0, w)} height={Math.max(0, h)} style={{ fill: CLASS_META[c].cssVar, fillOpacity: 0.06 }} />
            <text x={lx} y={ly} textAnchor={anchor} style={{ fill: CLASS_META[c].cssVar, fontSize: 12, fontWeight: 600 }}>{CLASS_META[c].label}</text>
        </g>
    );
    const tx = g.tx ?? (x0 + x1) / 2;
    const ty = g.ty ?? (y0 + y1) / 2;

    return (
        <div className="position-relative" data-testid="menu-matrix-chart" ref={box}>
            <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label="Matriz da engenharia de menu: quota das vendas na família e margem por unidade" style={{ display: "block", overflow: "visible" }}>
                {/* Quadrantes */}
                {quad("quebra_cabecas", x0, y0, tx - x0, ty - y0, x0 + 8, y0 + 16, "start")}
                {quad("estrela", tx, y0, x1 - tx, ty - y0, x1 - 8, y0 + 16, "end")}
                {quad("cao", x0, ty, tx - x0, y1 - ty, x0 + 8, y1 - 8, "start")}
                {quad("cavalo", tx, ty, x1 - tx, y1 - ty, x1 - 8, y1 - 8, "end")}
                {/* Grelha e eixos */}
                {g.xa.ticks.map((v) => (
                    <g key={`x${v}`}>
                        <line x1={g.sx(v)} x2={g.sx(v)} y1={y0} y2={y1} style={{ stroke: "var(--vz-border-color)", strokeOpacity: 0.6 }} />
                        <text x={g.sx(v)} y={y1 + 18} textAnchor="middle" style={{ fill: "var(--vz-secondary-color)", fontSize: 12 }}>{`${v.toLocaleString("pt-PT")} %`}</text>
                    </g>
                ))}
                {g.ya.ticks.map((v) => (
                    <g key={`y${v}`}>
                        <line x1={x0} x2={x1} y1={g.sy(v)} y2={g.sy(v)} style={{ stroke: "var(--vz-border-color)", strokeOpacity: 0.6 }} />
                        <text x={x0 - 8} y={g.sy(v) + 4} textAnchor="end" style={{ fill: "var(--vz-secondary-color)", fontSize: 12 }}>{fmtEur(v, g.ya.step < 1 ? 2 : 0)}</text>
                    </g>
                ))}
                <text x={(x0 + x1) / 2} y={H - 6} textAnchor="middle" style={{ fill: "var(--vz-secondary-color)", fontSize: 12 }}>Quota da quantidade vendida na família</text>
                <text x={14} y={(y0 + y1) / 2} textAnchor="middle" transform={`rotate(-90 14 ${(y0 + y1) / 2})`} style={{ fill: "var(--vz-secondary-color)", fontSize: 12 }}>Margem por unidade</text>
                {/* Limiares, com o rótulo na horizontal no fim da linha */}
                {g.tx !== null && (
                    <g>
                        <line x1={g.tx} x2={g.tx} y1={y0} y2={y1} style={{ stroke: "var(--vz-body-color)", strokeDasharray: "5 4", strokeOpacity: 0.7 }} />
                        <text x={g.tx + 6} y={y0 - 8} textAnchor="start" style={{ fill: "var(--vz-body-color)", fontSize: 12 }}>{`Popularidade ${fmtPct(threshold, 0)}`}</text>
                    </g>
                )}
                {g.ty !== null && (
                    <g>
                        <line x1={x0} x2={x1} y1={g.ty} y2={g.ty} style={{ stroke: "var(--vz-body-color)", strokeDasharray: "5 4", strokeOpacity: 0.7 }} />
                        <text x={x1} y={g.ty - 6} textAnchor="end" style={{ fill: "var(--vz-body-color)", fontSize: 12 }}>{`Margem média ${fmtEur(line)}`}</text>
                    </g>
                )}
                {/* Bolhas (as maiores atrás) */}
                {g.bubbles.map((b) => {
                    const color = b.item.class ? CLASS_META[b.item.class].cssVar : "var(--vz-secondary-color)";
                    const partial = b.item.confidence === "parcial";
                    return (
                        <circle key={b.item.product_id} cx={b.cx} cy={b.cy} r={b.r} tabIndex={0} role="button" data-testid="menu-bubble"
                            aria-label={`${b.item.description}: ${b.item.class ? CLASS_META[b.item.class].label : ""}, ${CONFIDENCE_META[b.item.confidence].label}`}
                            style={{ fill: color, fillOpacity: partial ? 0.35 : 0.85, stroke: color, strokeWidth: 2, strokeDasharray: partial ? "4 3" : undefined, cursor: "pointer" }}
                            onMouseEnter={() => setHover({ item: b.item, x: b.cx, y: b.cy - b.r })} onMouseLeave={() => setHover(null)}
                            onFocus={() => setHover({ item: b.item, x: b.cx, y: b.cy - b.r })} onBlur={() => setHover(null)}
                            onClick={() => onOpen(b.item)} onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onOpen(b.item); } }} />
                    );
                })}
                {g.labels.map((l) => (
                    <text key={`l${l.key}`} x={l.x} y={l.y} textAnchor={l.anchor} style={{ fill: "var(--vz-body-color)", fontSize: 12, pointerEvents: "none" }}>{l.text}</text>
                ))}
            </svg>
            {hover && (
                <div className="position-absolute card shadow-sm p-2 fs-12 mb-0" role="tooltip" data-testid="menu-tooltip"
                    style={{ left: `${(hover.x / W) * 100}%`, top: `${(hover.y / H) * 100}%`, transform: "translate(-50%, calc(-100% - 8px))", minWidth: 220, pointerEvents: "none", zIndex: 5 }}>
                    <div className="fw-semibold mb-1">{hover.item.description}</div>
                    <div>{fmtQty(hover.item.qty)} vendidos · quota {fmtPct(hover.item.share_pct)}</div>
                    <div>Preço sem IVA {fmtEur(hover.item.price_eur)} · custo {costLabel(hover.item)}</div>
                    <div>Margem {fmtEur(hover.item.margin_eur)} · food cost {fmtPct(hover.item.food_cost_pct)}</div>
                    <div className="mt-1">
                        {hover.item.class && <span className={`badge bg-${CLASS_META[hover.item.class].color}-subtle text-${CLASS_META[hover.item.class].color} me-1`}>{CLASS_META[hover.item.class].label}</span>}
                        <span className={`badge bg-${CONFIDENCE_META[hover.item.confidence].color}-subtle text-${CONFIDENCE_META[hover.item.confidence].color}`}>{CONFIDENCE_META[hover.item.confidence].label}</span>
                    </div>
                </div>
            )}
            <div className="d-flex flex-wrap justify-content-center gap-3 fs-12 mt-1" data-testid="menu-matrix-legend">
                {(Object.keys(CLASS_META) as MenuClass[]).map((c) => (
                    <span key={c} className="d-inline-flex align-items-center gap-1">
                        <svg width="12" height="12" aria-hidden><circle cx="6" cy="6" r="5" style={{ fill: CLASS_META[c].cssVar, fillOpacity: 0.85 }} /></svg>{CLASS_META[c].label}
                    </span>
                ))}
                <span className="d-inline-flex align-items-center gap-1 text-muted">
                    <svg width="12" height="12" aria-hidden><circle cx="6" cy="6" r="5" style={{ fill: "var(--vz-secondary-color)", fillOpacity: 0.35, stroke: "var(--vz-secondary-color)", strokeDasharray: "2 2" }} /></svg>
                    Cor clara e contorno tracejado: custo parcial (um mínimo)
                </span>
            </div>
        </div>
    );
}
