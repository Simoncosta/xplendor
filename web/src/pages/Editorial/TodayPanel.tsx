import { useCallback, useEffect, useState } from "react";
import { Badge, Button } from "reactstrap";
import { getEditorialToday } from "helpers/laravel_helper";
import { POST_CHANNEL_META } from "common/models/editorialPost.model";
import type { TodayData, TodayPost } from "common/models/editorialWorkflow.model";
import MarkPublishedModal from "./MarkPublishedModal";

/**
 * "Para publicar hoje" (F3d): as Programadas de hoje e as atrasadas (data e hora
 * passaram sem serem marcadas como publicadas). Só aparece quando há alguma.
 */

const dm = (iso: string) => { const [, m, d] = iso.split("-"); return `${d}/${m}`; };

type Props = { companyId: number; reloadKey: number; onOpen: (postId: number) => void; onChanged: () => void };

export default function TodayPanel({ companyId, reloadKey, onOpen, onChanged }: Props) {
    const [data, setData] = useState<TodayData | null>(null);
    const [marking, setMarking] = useState<TodayPost | null>(null);

    const load = useCallback(() => {
        getEditorialToday(companyId).then((r: any) => setData(r.data)).catch(() => setData(null));
    }, [companyId]);
    useEffect(() => { load(); }, [load, reloadKey]);

    if (!data || (data.today.length === 0 && data.overdue.length === 0)) return null;

    const overdueIds = new Set(data.overdue.map((p) => p.id));
    const row = (p: TodayPost, showDate: boolean) => (
        <li key={p.id} className="d-flex flex-wrap align-items-center gap-2 py-1">
            <span className="text-muted fs-12 text-nowrap" style={{ minWidth: 44 }}>{showDate ? dm(p.publish_date) : p.publish_time ?? "Sem hora"}</span>
            <i className={POST_CHANNEL_META[p.channel].icon} aria-label={POST_CHANNEL_META[p.channel].label} />
            <button type="button" className="btn btn-link p-0 fs-13 text-start text-body text-truncate" style={{ maxWidth: "100%" }} onClick={() => onOpen(p.id)}>{p.title}</button>
            {overdueIds.has(p.id) && <Badge color="danger" className="fw-normal">Atrasada</Badge>}
            {p.can_mark && (
                <Button size="sm" color="soft-success" className="ms-auto py-0" onClick={() => setMarking(p)}>
                    <i className="ri-checkbox-circle-line me-1" />Marcar como publicada
                </Button>
            )}
        </li>
    );
    const earlier = data.overdue.filter((p) => p.publish_date !== data.date);

    return (
        <div className={`border rounded p-2 px-3 mb-3 ${earlier.length ? "border-danger-subtle" : "border-success-subtle"}`}>
            <div className="row g-3">
                <div className={earlier.length ? "col-lg-6" : "col-12"}>
                    <div className="fw-semibold fs-13 mb-1"><i className="ri-send-plane-line me-1 text-success" />Para publicar hoje ({data.today.length})</div>
                    {data.today.length === 0 ? <p className="text-muted fs-12 mb-0">Nada programado para hoje.</p> : <ul className="list-unstyled mb-0">{data.today.map((p) => row(p, false))}</ul>}
                </div>
                {earlier.length > 0 && (
                    <div className="col-lg-6">
                        <div className="fw-semibold fs-13 mb-1 text-danger"><i className="ri-alarm-warning-line me-1" />Atrasadas de dias anteriores ({earlier.length})</div>
                        <ul className="list-unstyled mb-0">{earlier.map((p) => row(p, true))}</ul>
                    </div>
                )}
            </div>
            <MarkPublishedModal isOpen={!!marking} toggle={() => setMarking(null)} companyId={companyId} post={marking}
                onDone={() => { setMarking(null); load(); onChanged(); }} />
        </div>
    );
}
