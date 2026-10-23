import { useEffect, useState } from "react";
import { Offcanvas, OffcanvasBody, OffcanvasHeader } from "reactstrap";
import { toast } from "react-toastify";
import { CalendarList, RadarReactModal, RadarSignalCard, useRadar } from "./RadarCard";
import { useCompassCalendar } from "./CompassCalendarCard";
import { reactCompassCalendar } from "helpers/laravel_helper";
import type { RadarCalendarEntry, RadarSignal } from "common/models/radar.model";
import Skeleton from "Components/Common/Skeleton";

/** "Está a subir": os cartões do Radar, em versão curta (só com o módulo radar). */
function RisingSignals({ companyId, isOpen, onChanged }: { companyId: number; isOpen: boolean; onChanged?: () => void }) {
    const { data, loading, load, ignore } = useRadar(companyId);
    const [target, setTarget] = useState<RadarSignal | null>(null);
    useEffect(() => { if (isOpen) void load(); }, [isOpen, load]);
    return (
        <>
            <h6 className="text-uppercase text-muted fs-12 mb-2">Está a subir</h6>
            {!data && loading && <Skeleton variant="lines" lines={4} className="py-2" />}
            {data?.sector_missing && <p className="text-muted mb-0">Escolha o ramo da sua marca para o Radar saber o que lhe interessa.</p>}
            {data && !data.sector_missing && data.signals.length === 0 && <p className="text-muted fs-13 mb-0">Nada a subir agora para o seu ramo.</p>}
            <div className="d-flex flex-column gap-3">
                {data?.signals.map((s) => (
                    <RadarSignalCard key={s.id} compact signal={s} canAct={data.can_act} canActReason={data.can_act_reason}
                        onReact={setTarget} onIgnore={ignore} />
                ))}
            </div>
            <RadarReactModal companyId={companyId} target={target ? { kind: "signal", signal: target } : null} formats={data?.formats ?? ["Imagem única"]}
                onClose={() => setTarget(null)} onDone={(msg) => { setTarget(null); toast.success(msg); void load(); onChanged?.(); }} />
        </>
    );
}

/** "Vem aí": as datas oficiais do ramo e do concelho (Bússola 5), com ou sem o módulo radar. */
function UpcomingDates({ companyId, isOpen, onChanged }: { companyId: number; isOpen: boolean; onChanged?: () => void }) {
    const { data, loading, load } = useCompassCalendar(companyId);
    const [target, setTarget] = useState<RadarCalendarEntry | null>(null);
    useEffect(() => { if (isOpen) void load(); }, [isOpen, load]);
    return (
        <>
            <h6 className="text-uppercase text-muted fs-12 mb-2 mt-3">Vem aí</h6>
            {!data && loading && <Skeleton variant="lines" lines={3} className="py-2" />}
            {data && <CalendarList entries={data.calendar} canAct={data.can_act} canActReason={data.can_act_reason} onReact={setTarget} />}
            <RadarReactModal companyId={companyId} target={target ? { kind: "date", entry: target } : null} formats={data?.formats ?? ["Imagem única"]}
                send={reactCompassCalendar} onClose={() => setTarget(null)} onDone={(msg) => { setTarget(null); toast.success(msg); void load(); onChanged?.(); }} />
        </>
    );
}

/**
 * Painel lateral "Agora" da Linha Editorial: os cartões do Radar em versão curta (com o módulo radar)
 * e as datas que vêm aí (sempre), para reagir sem sair do calendário. "Reagir" cria a ideia sem
 * texto; a lista do calendário volta a carregar a seguir (onChanged).
 */
export default function RadarNowPanel({ companyId, isOpen, onClose, onChanged, hasRadar = true }: {
    companyId: number; isOpen: boolean; onClose: () => void; onChanged?: () => void; hasRadar?: boolean;
}) {
    return (
        <Offcanvas isOpen={isOpen} toggle={onClose} direction="end" data-testid="editorial-now-panel">
            <OffcanvasHeader toggle={onClose}>Agora</OffcanvasHeader>
            <OffcanvasBody>
                <p className="text-muted fs-13">{hasRadar ? "O que está a subir agora e as datas que vêm aí para o ramo da sua marca." : "As datas que vêm aí para o ramo e o concelho da sua marca."} Nunca publica nem escreve o texto.</p>
                {hasRadar && <RisingSignals companyId={companyId} isOpen={isOpen} onChanged={onChanged} />}
                <UpcomingDates companyId={companyId} isOpen={isOpen} onChanged={onChanged} />
            </OffcanvasBody>
        </Offcanvas>
    );
}
