import { useState } from "react";
import { Card, CardBody, Col, Row } from "reactstrap";
import { toast } from "react-toastify";
import { CompassData, CompassNumber, CompassPlay, fmtEur, fmtPct } from "common/models/bussola.model";
import PlayCard from "./PlayCard";
import { PlayCaptionModal, PlayDetailModal, PlayPostModal, PostPrefill } from "./PlayModals";

/**
 * Bússola, o topo e as 3 jogadas da semana. O MESMO componente na página e no separador
 * "Marketing e resultados" do dashboard do restaurante. Topo à maneira da secção e dos
 * widgets do dashboard de e-commerce do Velzon (saudação, data dos dados, seletor de loja e
 * números com avatar e ícone).
 */
type Props = {
    companyId: number;
    data: CompassData;
    /** Mudar de loja (0 = todas). Sem esta função, não há seletor (ex.: dashboard com o seu próprio). */
    onLocation?: (id: number) => void;
    onChanged?: () => void;
};

const dmy = (d?: string | null) => (d ? new Date(d.length === 10 ? `${d}T00:00:00` : d).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
const hm = (d?: string | null) => (d ? new Date(d).toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" }) : "");

export default function BussolaSummary({ companyId, data, onLocation, onChanged }: Props) {
    const [creating, setCreating] = useState<{ play: CompassPlay; prefill?: PostPrefill } | null>(null);
    const [suggesting, setSuggesting] = useState<CompassPlay | null>(null);
    const [detail, setDetail] = useState<CompassPlay | null>(null);
    const top = data.top;
    if (!top) return null;
    const locationId = data.location_id || null;

    const numberTile = (n: CompassNumber, i: number) => {
        const signed = n.format === "pct_signed";
        const tone = signed ? ((n.value ?? 0) < 0 ? "danger" : "success") : ["primary", "info", "warning", "success"][i % 4];
        const value = n.format === "eur" ? fmtEur(n.value ?? 0) : fmtPct(n.value, signed);
        return (
            <Col xl={3} md={6} key={`${n.kind}-${i}`}>
                <Card className="card-animate mb-3 mb-xl-0" data-testid={`compass-number-${n.kind}`}>
                    <CardBody>
                        <p className="text-uppercase fw-medium text-muted text-truncate mb-0" title={n.label}>{n.label}</p>
                        <div className="d-flex align-items-end justify-content-between mt-3">
                            <div className="min-w-0">
                                <h4 className={`fs-22 fw-semibold ff-secondary mb-1 ${signed ? `text-${tone}` : ""}`}>
                                    {signed && n.value !== null && <i className={`${(n.value ?? 0) < 0 ? "ri-arrow-right-down-line" : "ri-arrow-right-up-line"} fs-16 align-middle me-1`} aria-hidden />}
                                    {value}
                                </h4>
                                <span className="text-muted fs-12">{n.caption}</span>
                            </div>
                            <div className="avatar-sm flex-shrink-0">
                                <span className={`avatar-title rounded fs-3 bg-${tone}-subtle`}><i className={`${n.icon} text-${tone}`} aria-hidden /></span>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            </Col>
        );
    };

    return (
        <div data-testid="bussola-summary">
            <div className="d-flex flex-column flex-lg-row align-items-lg-center gap-2 mb-3">
                <div className="flex-grow-1">
                    <h4 className="fs-16 mb-1">{top.title}</h4>
                    <p className="text-muted mb-0 fs-13">
                        Dados até {dmy(data.data_until)}; atualizados a {dmy(data.computed_at)}, às {hm(data.computed_at)}. Últimas 4 semanas ({dmy(top.period.from)} a {dmy(top.period.to)}) contra as 4 anteriores.
                    </p>
                </div>
                {onLocation && data.locations.length > 1 && (
                    <div className="xp-seg flex-shrink-0" role="radiogroup" aria-label="Loja">
                        {[{ id: 0, name: "Todas as lojas" }, ...data.locations].map((l) => (
                            <button key={l.id} type="button" role="radio" aria-checked={data.location_id === l.id} className={data.location_id === l.id ? "on" : ""} onClick={() => onLocation(l.id)}>{l.name}</button>
                        ))}
                    </div>
                )}
            </div>

            <Row className="g-3 mb-3">{top.numbers.map(numberTile)}</Row>

            {top.notes.length > 0 && (
                <div className="bg-light rounded p-3 mb-3 fs-13" data-testid="compass-notes">
                    {top.notes.map((t, i) => <p key={i} className={i === top.notes.length - 1 ? "mb-0" : "mb-2"}><i className="ri-information-line text-muted me-1" aria-hidden />{t}</p>)}
                </div>
            )}

            <div className="d-flex align-items-center gap-2 mb-2">
                <h5 className="mb-0 flex-grow-1">As 3 jogadas da semana</h5>
            </div>
            {data.plays.length === 0 ? (
                <Card><CardBody className="text-muted">Esta semana não há jogadas: nenhum sinal das vendas e das reservas destas lojas pede uma ação.</CardBody></Card>
            ) : (
                <Row className="g-3 mb-3">
                    {data.plays.map((p) => (
                        <Col key={p.key} xl={4} md={6}>
                            <PlayCard play={p} canAct={!!data.can_act} canActReason={data.can_act_reason}
                                onCreate={(play) => setCreating({ play })} onSuggest={setSuggesting} onDetail={setDetail} />
                        </Col>
                    ))}
                </Row>
            )}

            <PlayPostModal companyId={companyId} locationId={locationId} play={creating?.play ?? null} prefill={creating?.prefill} formats={data.formats ?? ["Imagem única"]}
                onClose={() => setCreating(null)} onCreated={(msg) => { setCreating(null); toast.success(msg); onChanged?.(); }} />
            <PlayCaptionModal companyId={companyId} locationId={locationId} play={suggesting} onClose={() => setSuggesting(null)}
                onUse={(play, prefill) => { setSuggesting(null); setCreating({ play, prefill }); }} />
            <PlayDetailModal play={detail} onClose={() => setDetail(null)} />
        </div>
    );
}
