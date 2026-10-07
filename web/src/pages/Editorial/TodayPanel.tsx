import { useCallback, useEffect, useState } from "react";
import { Badge, Button } from "reactstrap";
import { getEditorialToday } from "helpers/laravel_helper";
import { POST_CHANNEL_META, channelIcons } from "common/models/editorialPost.model";
import type { TodayData, TodayPost } from "common/models/editorialWorkflow.model";

/**
 * "Para publicar hoje": as Programadas de hoje e as atrasadas (data e hora passaram com
 * alguma rede por publicar). Só aparece quando há alguma; recolhe-se (fica lembrado).
 * "Marcar como publicada" abre o painel da publicação em Publicação e Análise.
 */

const dm = (iso: string) => { const [, m, d] = iso.split("-"); return `${d}/${m}`; };
const COLLAPSE_KEY = "xp-editorial-today-collapsed";

/** "Instagram publicada; Facebook por publicar" (numa publicação com duas redes). */
const networkLine = (p: TodayPost) => p.networks.length < 2 ? null : p.networks.map((n) =>
    `${POST_CHANNEL_META[n.network].label} ${n.state === "published" ? "publicada" : n.state === "skipped" ? "dispensada" : "por publicar"}`).join("; ");

type Props = { companyId: number; reloadKey: number; onOpen: (postId: number, toPublish?: boolean) => void };

export default function TodayPanel({ companyId, reloadKey, onOpen }: Props) {
    const [data, setData] = useState<TodayData | null>(null);
    const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem(COLLAPSE_KEY) === "1"; } catch { return false; } });

    const load = useCallback(() => {
        getEditorialToday(companyId).then((r: any) => setData(r.data)).catch(() => setData(null));
    }, [companyId]);
    useEffect(() => { load(); }, [load, reloadKey]);

    if (!data || (data.today.length === 0 && data.overdue.length === 0)) return null;

    const toggle = () => setCollapsed((c) => {
        try { localStorage.setItem(COLLAPSE_KEY, c ? "0" : "1"); } catch { /* sem armazenamento */ }
        return !c;
    });
    const overdueIds = new Set(data.overdue.map((p) => p.id));
    const earlier = data.overdue.filter((p) => p.publish_date !== data.date);
    const list = [...earlier, ...data.today];

    return (
        <div className={`border rounded px-3 py-2 mb-3 ${data.overdue.length ? "border-danger-subtle" : "border-success-subtle"}`}>
            <div className="d-flex align-items-center gap-2">
                <i className="ri-send-plane-line text-success" />
                <strong className="fs-13">Para publicar hoje ({data.today.length})</strong>
                {data.overdue.length > 0 && <Badge color="danger">{data.overdue.length} {data.overdue.length === 1 ? "atrasada" : "atrasadas"}</Badge>}
                <button type="button" className="btn btn-link btn-sm p-0 ms-auto text-muted text-decoration-none" aria-expanded={!collapsed} onClick={toggle}>
                    {collapsed ? "Mostrar" : "Recolher"} <i className={collapsed ? "ri-arrow-down-s-line" : "ri-arrow-up-s-line"} />
                </button>
            </div>
            {!collapsed && (
                <ul className="list-unstyled mb-0 mt-1">
                    {list.map((p) => (
                        <li key={p.id} className="d-flex flex-wrap align-items-center gap-2 py-1 fs-13">
                            <span className="text-muted fs-12 text-nowrap" style={{ minWidth: 44 }}>{p.publish_date !== data.date ? dm(p.publish_date) : p.publish_time ?? "Sem hora"}</span>
                            <span className="d-flex gap-1">{channelIcons(p).map((c) => <i key={c.icon} className={c.icon} aria-label={c.label} />)}</span>
                            <button type="button" className="btn btn-link p-0 fs-13 text-start text-body" onClick={() => onOpen(p.id)}>{p.title}</button>
                            {overdueIds.has(p.id) && <Badge color="danger" className="fw-normal">Atrasada</Badge>}
                            {networkLine(p) && <span className="text-muted fs-12">{networkLine(p)}</span>}
                            {p.can_mark && (
                                <Button size="sm" color="success" className="ms-auto py-0" onClick={() => onOpen(p.id, true)}>
                                    <i className="ri-checkbox-circle-line me-1" />Marcar como publicada
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
