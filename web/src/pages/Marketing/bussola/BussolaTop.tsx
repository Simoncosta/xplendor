import { Card, CardBody, Col, Row } from "reactstrap";
import { CompassData, CompassNumber, fmtEur, fmtPct } from "common/models/bussola.model";
import { StoreSelector } from "./BussolaPlays";

/**
 * Bússola, o cartão do topo: o subtítulo, "Esta semana em [empresa]", a linha das datas, o
 * seletor de loja, os 4 números (à maneira dos widgets do dashboard de e-commerce do Velzon,
 * em molduras dentro do mesmo cartão) e as frases da descida de cada loja.
 */
const dmy = (d?: string | null) => (d ? new Date(d.length === 10 ? `${d}T00:00:00` : d).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
const hm = (d?: string | null) => (d ? new Date(d).toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" }) : "");

export default function BussolaTop({ data, onLocation }: { data: CompassData; onLocation: (id: number) => void }) {
    const top = data.top;
    if (!top) return null;

    const tile = (n: CompassNumber, i: number) => {
        const signed = n.format === "pct_signed";
        const tone = signed ? ((n.value ?? 0) < 0 ? "danger" : "success") : ["primary", "info", "warning", "success"][i % 4];
        return (
            <Col key={`${n.kind}-${i}`}>
                <div className="border border-dashed rounded h-100 p-3 d-flex align-items-center justify-content-between gap-2" data-testid={`compass-number-${n.kind}`}>
                    <div className="min-w-0">
                        <p className="text-uppercase fw-medium text-muted text-truncate fs-12 mb-1" title={n.label}>{n.label}</p>
                        <h4 className={`fs-22 fw-semibold ff-secondary mb-1 ${signed ? `text-${tone}` : ""}`}>
                            {signed && n.value !== null && <i className={`${(n.value ?? 0) < 0 ? "ri-arrow-right-down-line" : "ri-arrow-right-up-line"} fs-16 align-middle me-1`} aria-hidden />}
                            {n.format === "eur" ? fmtEur(n.value ?? 0) : fmtPct(n.value, signed)}
                        </h4>
                        <span className="text-muted fs-12">{n.caption}</span>
                    </div>
                    <div className="avatar-sm flex-shrink-0">
                        <span className={`avatar-title rounded fs-3 bg-${tone}-subtle`}><i className={`${n.icon} text-${tone}`} aria-hidden /></span>
                    </div>
                </div>
            </Col>
        );
    };

    return (
        <Card data-testid="bussola-top">
            <CardBody>
                <p className="text-muted fs-13 mb-2">O que fazer esta semana, a partir das vendas e das reservas do restaurante.</p>
                <div className="d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                    <div className="flex-grow-1">
                        <h4 className="fs-16 mb-1">{top.title}</h4>
                        <p className="text-muted mb-0 fs-13">
                            Dados até {dmy(data.data_until)}; atualizados a {dmy(data.computed_at)}, às {hm(data.computed_at)}. Últimas 4 semanas ({dmy(top.period.from)} a {dmy(top.period.to)}) contra as 4 anteriores.
                        </p>
                    </div>
                    <StoreSelector data={data} onLocation={onLocation} />
                </div>
                <Row className="row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 my-1 mb-3">{top.numbers.map(tile)}</Row>
                {top.notes.length > 0 && (
                    <div className="bg-light rounded p-3 fs-13" data-testid="compass-notes">
                        {top.notes.map((t, i) => <p key={i} className={i === top.notes.length - 1 ? "mb-0" : "mb-2"}><i className="ri-information-line text-muted me-1" aria-hidden />{t}</p>)}
                    </div>
                )}
            </CardBody>
        </Card>
    );
}
