import { useCallback, useEffect, useState } from "react";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { getCompassCalendar, reactCompassCalendar } from "helpers/laravel_helper";
import type { CompassCalendarData, RadarCalendarEntry } from "common/models/radar.model";
import { CalendarList, RadarReactModal } from "./RadarCard";

/** Bússola 5: as datas que vêm aí (GET marketing/calendar), sem o módulo radar. */
export function useCompassCalendar(companyId: number) {
    const [data, setData] = useState<CompassCalendarData | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res: any = await getCompassCalendar(companyId);
            setData(res?.data ?? null);
            setFailed(false);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, [companyId]);
    useEffect(() => { void load(); }, [load]);
    return { data, loading, failed, load };
}

/**
 * O bloco "Vem aí" da Bússola numa empresa sem o módulo radar: as datas com fonte oficial do ramo e
 * do concelho, e o feriado municipal indicado nas Configurações. "Reagir" cria a ideia sem texto.
 */
export default function CompassCalendarCard({ companyId }: { companyId: number }) {
    const { data, loading, failed, load } = useCompassCalendar(companyId);
    const [target, setTarget] = useState<RadarCalendarEntry | null>(null);
    return (
        <PageCard title="Vem aí" data-testid="compass-calendar-card" loading={loading && !!data}
            info="As datas com fonte oficial que interessam ao ramo e ao concelho da sua marca, e o feriado municipal indicado nas Configurações. Nunca publica nem escreve o texto.">
            <div className="p-3">
                {!data && loading && <Skeleton variant="lines" lines={3} className="py-2" />}
                {failed && !data && <div className="alert alert-warning mb-0">Não foi possível carregar as datas. Tente novamente dentro de momentos.</div>}
                {data && <CalendarList entries={data.calendar} canAct={data.can_act} canActReason={data.can_act_reason} onReact={setTarget} />}
            </div>
            <RadarReactModal companyId={companyId} target={target ? { kind: "date", entry: target } : null} formats={data?.formats ?? ["Imagem única"]}
                send={reactCompassCalendar} onClose={() => setTarget(null)} onDone={(msg) => { setTarget(null); toast.success(msg); void load(); }} />
        </PageCard>
    );
}
