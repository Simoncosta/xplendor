import { Col, Row } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import HiddenMoney, { FinancialNote } from "Components/Common/HiddenMoney";
import { fmtEur, fmtPct } from "common/models/bussola.model";
import type { StandCompassData, StandNumber } from "common/models/standCompass.model";

const dmy = (d?: string | null) => (d ? new Date(d.length === 10 ? `${d}T00:00:00` : d).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
const hm = (d?: string | null) => (d ? new Date(d).toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" }) : "");
const TONES = ["primary", "success", "info", "warning", "danger", "secondary"];

/**
 * Bússola do stand, o cartão do topo: os veículos em stock e o valor do stock, os dias médios,
 * a percentagem acima do mercado (só com comparação exata), o gasto em anúncios e o custo por
 * contacto das últimas 4 semanas. O contexto é dito uma só vez, dentro do cartão.
 */
export default function StandTop({ data }: { data: StandCompassData }) {
    const top = data.top;
    if (!top) return null;

    const value = (n: StandNumber, i: number) => {
        if (n.format === "eur") return n.hidden || n.value === null ? (n.hidden ? <HiddenMoney seed={i} /> : "Sem dados") : fmtEur(n.value);
        if (n.format === "pct") return fmtPct(n.value);
        return n.value === null ? "Sem dados" : n.value.toLocaleString("pt-PT");
    };

    return (
        <PageCard data-testid="bussola-top" title={top.title} flush={false}
            status={<>Dados de {dmy(data.data_until)}; calculados a {dmy(data.computed_at)}, às {hm(data.computed_at)}. {top.context}</>}>
            <Row className="row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-3">
                {top.numbers.map((n, i) => (
                    <Col key={n.kind}>
                        <div className="border border-dashed rounded h-100 p-3 d-flex align-items-center justify-content-between gap-2" data-testid={`compass-number-${n.kind}`}>
                            <div className="min-w-0">
                                <p className="text-uppercase fw-medium text-muted text-truncate fs-12 mb-1" title={n.label}>{n.label}</p>
                                <h4 className="fs-22 fw-semibold ff-secondary mb-1">{value(n, i)}</h4>
                                <span className="text-muted fs-12">{n.caption}</span>
                            </div>
                            <div className="avatar-sm flex-shrink-0">
                                <span className={`avatar-title rounded fs-3 bg-${TONES[i % TONES.length]}-subtle`}><i className={`${n.icon} text-${TONES[i % TONES.length]}`} aria-hidden /></span>
                            </div>
                        </div>
                    </Col>
                ))}
            </Row>
            {data.financial?.visible === false && <FinancialNote className="mb-3" />}
            {top.notes.length > 0 && (
                <div className="bg-light rounded p-3 fs-13" data-testid="compass-notes">
                    {top.notes.map((t, i) => <p key={i} className={i === top.notes.length - 1 ? "mb-0" : "mb-2"}><i className="ri-information-line text-muted me-1" aria-hidden />{t}</p>)}
                </div>
            )}
        </PageCard>
    );
}
