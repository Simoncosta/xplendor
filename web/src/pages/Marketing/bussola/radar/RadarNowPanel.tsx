import { useEffect, useState } from "react";
import { Offcanvas, OffcanvasBody, OffcanvasHeader } from "reactstrap";
import { toast } from "react-toastify";
import { RadarReactModal, RadarSignalCard, useRadar } from "./RadarCard";
import type { RadarSignal } from "common/models/radar.model";
import Skeleton from "Components/Common/Skeleton";

/**
 * Painel lateral "Agora" da Linha Editorial: os mesmos cartões do Radar, em versão curta, para
 * reagir sem sair do calendário. "Reagir" cria a ideia sem texto; a lista do calendário volta a
 * carregar a seguir (onChanged).
 */
export default function RadarNowPanel({ companyId, isOpen, onClose, onChanged }: { companyId: number; isOpen: boolean; onClose: () => void; onChanged?: () => void }) {
    const { data, loading, load, ignore } = useRadar(companyId);
    const [target, setTarget] = useState<RadarSignal | null>(null);
    useEffect(() => { if (isOpen) void load(); }, [isOpen, load]);

    return (
        <Offcanvas isOpen={isOpen} toggle={onClose} direction="end" data-testid="editorial-now-panel">
            <OffcanvasHeader toggle={onClose}>Agora</OffcanvasHeader>
            <OffcanvasBody>
                <p className="text-muted fs-13">O que está a subir agora para o ramo da sua marca. O Radar nunca publica nem escreve o texto.</p>
                {!data && loading && <Skeleton variant="lines" lines={4} className="py-2" />}
                {data?.sector_missing && <p className="text-muted mb-0">Escolha o ramo da sua marca para o Radar saber o que lhe interessa.</p>}
                {data && !data.sector_missing && data.signals.length === 0 && <p className="text-muted mb-0">Nada a subir agora para o seu ramo.</p>}
                <div className="d-flex flex-column gap-3">
                    {data?.signals.map((s) => (
                        <RadarSignalCard key={s.id} compact signal={s} canAct={data.can_act} canActReason={data.can_act_reason}
                            onReact={setTarget} onIgnore={ignore} />
                    ))}
                </div>
            </OffcanvasBody>
            <RadarReactModal companyId={companyId} target={target ? { kind: "signal", signal: target } : null} formats={data?.formats ?? ["Imagem única"]}
                onClose={() => setTarget(null)} onDone={(msg) => { setTarget(null); toast.success(msg); void load(); onChanged?.(); }} />
        </Offcanvas>
    );
}
