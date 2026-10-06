import { useEffect, useMemo, useState } from "react";
import { Spinner } from "reactstrap";
import { getEditorialResults } from "helpers/laravel_helper";
import { MEDIA_FORMATS, POST_CHANNEL_META } from "common/models/editorialPost.model";
import { ResultRow, STAGE_META, fmtInt, fmtRate } from "common/models/editorialWorkflow.model";

/**
 * Resultados do mês (F3d): uma linha por publicação das redes publicada ou em Análise,
 * para cruzar a estratégia (formato, pilar) com os resultados. Ordenável por coluna.
 */

type Key = "date" | "channel" | "format" | "pillar" | "reach" | "engagement_rate";
const COLUMNS: { key: Key; label: string; numeric?: boolean }[] = [
    { key: "date", label: "Data" }, { key: "channel", label: "Canal" }, { key: "format", label: "Formato" },
    { key: "pillar", label: "Pilar" }, { key: "reach", label: "Alcance", numeric: true }, { key: "engagement_rate", label: "Envolvimento", numeric: true },
];
const formatLabel = (r: ResultRow) => {
    const all = [...(MEDIA_FORMATS.instagram ?? []), ...(MEDIA_FORMATS.facebook ?? [])];
    return all.find((f) => f.value === r.media_format)?.label ?? r.format;
};
const dmy = (iso: string) => iso.split("-").reverse().join("/");

export default function MonthResults({ companyId, month, monthLabel, onOpen }: { companyId: number; month: string; monthLabel: string; onOpen: (id: number) => void }) {
    const [rows, setRows] = useState<ResultRow[] | null>(null);
    const [sort, setSort] = useState<{ key: Key; dir: 1 | -1 }>({ key: "date", dir: 1 });

    useEffect(() => {
        setRows(null);
        getEditorialResults(companyId, month).then((r: any) => setRows(r.data.rows)).catch(() => setRows([]));
    }, [companyId, month]);

    const sorted = useMemo(() => {
        if (!rows) return [];
        const val = (r: ResultRow): string | number | null => (sort.key === "format" ? formatLabel(r) : (r[sort.key] as any));
        // Sem valor fica sempre no fim, seja qual for o sentido.
        return [...rows].sort((a, b) => {
            const va = val(a); const vb = val(b);
            if (va === null || va === "") return vb === null || vb === "" ? 0 : 1;
            if (vb === null || vb === "") return -1;
            return (typeof va === "number" && typeof vb === "number" ? va - vb : String(va).localeCompare(String(vb), "pt")) * sort.dir;
        });
    }, [rows, sort]);

    const head = (c: (typeof COLUMNS)[number]) => {
        const active = sort.key === c.key;
        return (
            <th key={c.key} scope="col" className={c.numeric ? "text-end" : ""} aria-sort={active ? (sort.dir === 1 ? "ascending" : "descending") : "none"}>
                <button type="button" className="btn btn-link btn-sm p-0 text-body fw-semibold text-decoration-none text-nowrap"
                    onClick={() => setSort({ key: c.key, dir: active ? (sort.dir === 1 ? -1 : 1) : (c.numeric ? -1 : 1) })}>
                    {c.label} <i className={active ? (sort.dir === 1 ? "ri-arrow-up-s-line" : "ri-arrow-down-s-line") : "ri-arrow-up-down-line text-muted"} />
                </button>
            </th>
        );
    };

    if (!rows) return <div className="text-center py-5"><Spinner /></div>;

    return (
        <div>
            <p className="text-muted fs-12">Resultados de {monthLabel}: publicações das redes já publicadas. Os números registam-se na janela de cada publicação. A taxa de envolvimento é interações ÷ alcance × 100 e só aparece quando há alcance.</p>
            {rows.length === 0 ? (
                <div className="text-muted text-center border rounded py-4 fs-13">Ainda não há publicações publicadas neste mês.</div>
            ) : (
                <div className="table-responsive">
                    <table className="table table-sm table-hover align-middle fs-13 mb-0">
                        <thead className="table-light"><tr><th scope="col">Publicação</th>{COLUMNS.map(head)}</tr></thead>
                        <tbody>
                            {sorted.map((r) => (
                                <tr key={r.id}>
                                    <td>
                                        <button type="button" className="btn btn-link p-0 fs-13 text-start" onClick={() => onOpen(r.id)}>{r.title}</button>
                                        <div className="text-muted fs-11">{STAGE_META[r.stage].label}{r.published_url && <> · <a href={r.published_url} target="_blank" rel="noreferrer noopener">ver na rede</a></>}</div>
                                    </td>
                                    <td className="text-nowrap">{dmy(r.date)}</td>
                                    <td><i className={`${POST_CHANNEL_META[r.channel].icon} me-1`} />{POST_CHANNEL_META[r.channel].label}</td>
                                    <td>{formatLabel(r)}</td>
                                    <td>{r.pillar ?? <span className="text-muted">Sem pilar</span>}</td>
                                    <td className="text-end">{r.reach === null ? <span className="text-muted">Por registar</span> : fmtInt(r.reach)}</td>
                                    <td className="text-end">{r.engagement_rate === null ? <span className="text-muted">{r.reach === null || r.reach === 0 ? "Sem alcance" : "Sem interações"}</span> : fmtRate(r.engagement_rate)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
