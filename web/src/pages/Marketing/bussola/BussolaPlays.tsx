import React, { useState } from "react";
import { Col, Row } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import { toast } from "react-toastify";
import { confirmAction } from "helpers/swal";
import type { CompassData, CompassPlay } from "common/models/bussola.model";
import PlayCard from "./PlayCard";
import { CompassAdapter, restaurantAdapter } from "./compassAdapter";
import { PlayCaptionModal, PlayDetailModal, PlayPostModal, PostPrefill } from "./PlayModals";

/**
 * "As 3 jogadas da semana": o MESMO cartão na página da Bússola e no fim do separador
 * "Marketing e resultados" do dashboard do restaurante (junto às Recomendações). O seletor
 * de loja fica no cabeçalho, na linha do título, quando a função onLocation é dada.
 * Comum aos ramos: o adaptador dá as chamadas e os textos do ramo; headerActions substitui o
 * seletor de loja (o stand põe ali o seletor de tipo); renderButtons dá os botões do ramo.
 */
type Props = {
    companyId: number;
    data: CompassData;
    onLocation?: (id: number) => void;
    onChanged?: () => void;
    adapter?: CompassAdapter;
    headerActions?: React.ReactNode;
    /** Os botões de cada jogada (por omissão, "Criar publicação" e "Sugerir texto"). */
    playProps?: (p: CompassPlay) => { showCreate?: boolean; showSuggest?: boolean; extraButtons?: React.ReactNode };
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

export default function BussolaPlays({ companyId, data, onLocation, onChanged, adapter = restaurantAdapter, headerActions, playProps }: Props) {
    const [creating, setCreating] = useState<{ play: CompassPlay; prefill?: PostPrefill } | null>(null);
    const [suggesting, setSuggesting] = useState<CompassPlay | null>(null);
    const [detail, setDetail] = useState<CompassPlay | null>(null);
    const locationId = data.location_id || null;

    const ignore = async (play: CompassPlay, permanent: boolean) => {
        if (permanent) {
            const ok = await confirmAction({
                title: adapter.exclude.confirmTitle,
                text: adapter.exclude.confirmText,
                confirmText: "Não voltar a sugerir", icon: "question",
            });
            if (!ok) return;
        }
        try {
            await adapter.ignore(companyId, play, permanent);
            toast.success(permanent ? adapter.exclude.done : "Jogada escondida durante 4 semanas.");
            onChanged?.();
        } catch (e: any) { toast.error(e?.message ?? "Não foi possível ignorar."); }
    };

    return (
        <PageCard data-testid="bussola-plays" title="As 3 jogadas da semana" flush={false}
            actions={headerActions ?? (onLocation && data.locations.length >= 2 ? <StoreSelector data={data} onLocation={onLocation} /> : undefined)}>
                {data.plays.length === 0 ? (
                    <p className="text-muted mb-0">{adapter.empty}</p>
                ) : (
                    <Row className="g-3">
                        {data.plays.map((p) => (
                            <Col key={p.key} xl={4} md={6}>
                                <PlayCard companyId={companyId} play={p} canAct={!!data.can_act} canActReason={data.can_act_reason}
                                    onCreate={(play) => setCreating({ play })} onSuggest={setSuggesting} onDetail={setDetail} onIgnore={(pl, perm) => void ignore(pl, perm)}
                                    excludeLabel={adapter.exclude.label} canExclude={adapter.exclude.can} {...(playProps ? playProps(p) : {})} />
                            </Col>
                        ))}
                    </Row>
                )}

            <PlayPostModal companyId={companyId} locationId={locationId} play={creating?.play ?? null} prefill={creating?.prefill} formats={data.formats ?? ["Imagem única"]} api={adapter.api}
                onClose={() => setCreating(null)} onCreated={(msg) => { setCreating(null); toast.success(msg); onChanged?.(); }} />
            <PlayCaptionModal companyId={companyId} locationId={locationId} play={suggesting} api={adapter.api} onClose={() => setSuggesting(null)}
                onUse={(play, prefill) => { setSuggesting(null); setCreating({ play, prefill }); }} />
            <PlayDetailModal play={detail} onClose={() => setDetail(null)} itemsHeading={adapter.itemsHeading} />
        </PageCard>
    );
}
