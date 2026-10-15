import React from "react";
import useDelayedFlag from "hooks/useDelayedFlag";

/**
 * Esqueleto de carregamento (primeira carga, ainda sem dados), sobre os placeholders do Bootstrap
 * que o Velzon já traz. Regras (documents/design-system.md):
 *  · primeira carga → este esqueleto, com as dimensões do conteúdo final (sem saltos de layout);
 *  · recarga com dados no ecrã → os dados ficam visíveis, esbatidos, com um indicador pequeno no
 *    cabeçalho (PageCard / DataTable tratam disso) — nunca se volta ao esqueleto;
 *  · só aparece depois de ~200 ms (antes disso ocupa o espaço, invisível): não pisca;
 *  · erro e vazio ("Sem dados neste período") são estados próprios, não esqueletos;
 *  · os spinners ficam nos botões de ação e nos processos com progresso.
 * Acessível: aria-busy no contentor e "A carregar…" só para leitores de ecrã. Sem animação com
 * prefers-reduced-motion. Cores do tema (claro e escuro) em assets/css/xp-design.css.
 */
export type SkeletonVariant = "lines" | "block" | "kpi" | "value" | "table" | "chart" | "calendar";

type Props = {
    variant?: SkeletonVariant;
    /** lines: número de linhas de texto. */
    lines?: number;
    /** block / chart / kpi: altura (px ou CSS). */
    height?: number | string;
    /** table: linhas e colunas (as colunas visíveis da tabela), e se tem cabeçalho. */
    rows?: number;
    columns?: number;
    header?: boolean;
    /** calendar: semanas do mês e altura de cada semana (px); toolbar: a barra do mês por cima. */
    weeks?: number;
    weekHeight?: number;
    toolbar?: boolean;
    /** Atraso antes de aparecer (ms); 0 = já. */
    delay?: number;
    /** Texto para leitores de ecrã ("" quando faz parte de um esqueleto maior, que o diz uma vez). */
    label?: string;
    className?: string;
    style?: React.CSSProperties;
};

/** Larguras das linhas de texto (variam para parecer texto). */
const LINE_WIDTHS = ["100%", "92%", "76%", "85%", "62%"];
/** Alturas das barras do gráfico (%), fixas para não "dançar" entre renders. */
const BARS = [38, 55, 47, 68, 52, 74, 60, 81, 66, 58, 72, 49];

const bar = (style: React.CSSProperties, className = "") => <span className={`placeholder xp-skeleton-bar ${className}`} style={style} />;

/** Altura de uma linha de dados das tabelas (texto + botões sm), para o esqueleto não saltar. */
export const SKELETON_ROW_HEIGHT = 52;

/** As linhas de uma tabela (para usar dentro de um <tbody>: o DataTable usa-as). */
export function SkeletonRows({ rows = 5, columns = 4, visible = true }: { rows?: number; columns?: number; visible?: boolean }) {
    return (
        <>
            {Array.from({ length: rows }).map((_, i) => (
                <tr key={i} className={`xp-skeleton placeholder-glow ${visible ? "" : "xp-skeleton-pending"}`} style={{ height: SKELETON_ROW_HEIGHT }} data-testid="dt-skeleton" aria-hidden="true">
                    {Array.from({ length: Math.max(1, columns) }).map((__, j) => (
                        <td key={j}>{bar({ width: j === 0 ? "70%" : `${40 + ((i + j) % 3) * 15}%` })}</td>
                    ))}
                </tr>
            ))}
        </>
    );
}

export default function Skeleton({
    variant = "lines", lines = 3, height, rows = 5, columns = 4, header = true, weeks = 5, weekHeight = 101, toolbar = false,
    delay = 200, label = "A carregar…", className = "", style,
}: Props) {
    const visible = useDelayedFlag(true, delay);

    let body: React.ReactNode;
    switch (variant) {
        case "block":
            body = bar({ display: "block", width: "100%", height: height ?? 120 }, "rounded");
            break;
        case "kpi":
            body = (
                <div className="d-flex flex-column gap-2" style={{ minHeight: height }}>
                    {bar({ width: "40%", height: 10 })}
                    {bar({ width: "70%", height: 22 })}
                    {bar({ width: "55%", height: 10 })}
                </div>
            );
            break;
        case "value":
            // O valor de um KPI cujo rótulo fica visível: o número e, com lines=2, a linha de baixo.
            body = (
                <div className="d-flex flex-column" style={{ gap: 5 }}>
                    {bar({ width: "60%", height: 29 })}
                    {lines > 1 && bar({ width: "45%", height: 17 })}
                </div>
            );
            break;
        case "table":
            body = (
                <table className="table align-middle mb-0">
                    {header && (
                        <thead className="table-light">
                            <tr>{Array.from({ length: Math.max(1, columns) }).map((_, j) => <th key={j}>{bar({ width: "50%" })}</th>)}</tr>
                        </thead>
                    )}
                    <tbody>
                        {Array.from({ length: rows }).map((_, i) => (
                            <tr key={i}>
                                {Array.from({ length: Math.max(1, columns) }).map((__, j) => (
                                    <td key={j}>{bar({ width: j === 0 ? "70%" : `${40 + ((i + j) % 3) * 15}%` })}</td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            );
            break;
        case "chart":
            body = (
                <div className="xp-skeleton-chart" style={{ height: height ?? 280 }}>
                    {BARS.map((h, i) => bar({ height: `${h}%` }, "flex-fill rounded-top"))}
                </div>
            );
            break;
        case "calendar":
            body = (
                <>
                {toolbar && (
                    // A barra do mês (setas, "Hoje" e o título), como a do FullCalendar.
                    <div className="xp-skeleton-calendar-toolbar">
                        <div className="d-flex gap-1">{bar({ width: 27, height: 27 })}{bar({ width: 27, height: 27 })}{bar({ width: 42, height: 27 })}</div>
                        {bar({ width: 160, height: 18 })}
                        <div style={{ width: 104 }} />
                    </div>
                )}
                <div className="xp-skeleton-calendar">
                    <div className="xp-skeleton-calendar-head">
                        {Array.from({ length: 7 }).map((_, i) => <div key={i}>{bar({ width: "45%", height: 10 })}</div>)}
                    </div>
                    {Array.from({ length: weeks }).map((_, w) => (
                        <div key={w} className="xp-skeleton-calendar-week" style={{ height: weekHeight }}>
                            {Array.from({ length: 7 }).map((__, d) => (
                                <div key={d}>
                                    {bar({ width: 14, height: 10, alignSelf: "flex-end" })}
                                    {(w + d) % 3 !== 2 && <>{bar({ width: "70%", height: 10 })}{bar({ width: "50%", height: 8 })}</>}
                                </div>
                            ))}
                        </div>
                    ))}
                </div>
                </>
            );
            break;
        default:
            body = (
                <div>
                    {Array.from({ length: lines }).map((_, i) => (
                        <React.Fragment key={i}>{bar({ display: "block", width: LINE_WIDTHS[i % LINE_WIDTHS.length], height: 12, marginBottom: i < lines - 1 ? 10 : 0 })}</React.Fragment>
                    ))}
                </div>
            );
    }

    return (
        <div className={`xp-skeleton placeholder-glow ${visible ? "" : "xp-skeleton-pending"} ${className}`} style={style} aria-busy="true" data-testid="skeleton" data-variant={variant}>
            {label && <span className="visually-hidden">{label}</span>}
            <div aria-hidden="true">{body}</div>
        </div>
    );
}
