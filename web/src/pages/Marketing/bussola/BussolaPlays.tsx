import { useState } from "react";
import { Col, Row } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import { toast } from "react-toastify";
import { confirmAction } from "helpers/swal";
import { excludeRestaurantItem, ignoreRestaurantSignal } from "helpers/laravel_helper";
import type { CompassData, CompassPlay } from "common/models/bussola.model";
import PlayCard from "./PlayCard";
import { PlayCaptionModal, PlayDetailModal, PlayPostModal, PostPrefill } from "./PlayModals";

/**
 * "As 3 jogadas da semana": o MESMO cartão na página da Bússola e no fim do separador
 * "Marketing e resultados" do dashboard do restaurante (junto às Recomendações). O seletor
 * de loja fica no cabeçalho, na linha do título, quando a função onLocation é dada.
 */
type Props = {
    companyId: number;
    data: CompassData;
    onLocation?: (id: number) => void;
    onChanged?: () => void;
};

export function StoreSelector({ data, onLocation }: { data: CompassData; onLocation: (id: number) => void }) {
    if (data.locations.length < 2) return null;
    return (
        <div className="xp-seg flex-shrink-0" role="radiogroup" aria-label="Loja">
            {[{ id: 0, name: "Todas as lojas" }, ...data.locations].map((l) => (
                <button key={l.id} type="button" role="radio" aria-checked={data.location_id === l.id} className={data.location_id === l.id ? "on" : ""} onClick={() => onLocation(l.id)}>{l.name}</button>
            ))}
        </div>
    );
}

export default function BussolaPlays({ companyId, data, onLocation, onChanged }: Props) {
    const [creating, setCreating] = useState<{ play: CompassPlay; prefill?: PostPrefill } | null>(null);
    const [suggesting, setSuggesting] = useState<CompassPlay | null>(null);
    const [detail, setDetail] = useState<CompassPlay | null>(null);
    const locationId = data.location_id || null;

    const ignore = async (play: CompassPlay, permanent: boolean) => {
        if (permanent) {
            const ok = await confirmAction({
                title: "Não voltar a sugerir este artigo?",
                text: "O artigo deixa de entrar nas jogadas e nos rankings, sem mudar a categoria da família. Pode voltar a incluí-lo na página das categorias.",
                confirmText: "Não voltar a sugerir", icon: "question",
            });
            if (!ok) return;
        }
        try {
            if (permanent) await excludeRestaurantItem(companyId, play.signal_keys[0]);
            else for (const key of play.signal_keys) await ignoreRestaurantSignal(companyId, key);
            toast.success(permanent ? "O artigo não volta a ser sugerido." : "Jogada escondida durante 4 semanas.");
            onChanged?.();
        } catch (e: any) { toast.error(e?.message ?? "Não foi possível ignorar."); }
    };

    return (
        <PageCard data-testid="bussola-plays" title="As 3 jogadas da semana" flush={false}
            actions={onLocation && data.locations.length >= 2 ? <StoreSelector data={data} onLocation={onLocation} /> : undefined}>
                {data.plays.length === 0 ? (
                    <p className="text-muted mb-0">Esta semana não há jogadas: nenhum sinal das vendas e das reservas destas lojas pede uma ação.</p>
                ) : (
                    <Row className="g-3">
                        {data.plays.map((p) => (
                            <Col key={p.key} xl={4} md={6}>
                                <PlayCard companyId={companyId} play={p} canAct={!!data.can_act} canActReason={data.can_act_reason}
                                    onCreate={(play) => setCreating({ play })} onSuggest={setSuggesting} onDetail={setDetail} onIgnore={(pl, perm) => void ignore(pl, perm)} />
                            </Col>
                        ))}
                    </Row>
                )}

            <PlayPostModal companyId={companyId} locationId={locationId} play={creating?.play ?? null} prefill={creating?.prefill} formats={data.formats ?? ["Imagem única"]}
                onClose={() => setCreating(null)} onCreated={(msg) => { setCreating(null); toast.success(msg); onChanged?.(); }} />
            <PlayCaptionModal companyId={companyId} locationId={locationId} play={suggesting} onClose={() => setSuggesting(null)}
                onUse={(play, prefill) => { setSuggesting(null); setCreating({ play, prefill }); }} />
            <PlayDetailModal play={detail} onClose={() => setDetail(null)} />
        </PageCard>
    );
}
