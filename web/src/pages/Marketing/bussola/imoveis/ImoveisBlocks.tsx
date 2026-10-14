import { Badge } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import type { ImoveisCompassData } from "common/models/imoveisCompass.model";
import { IMOVEIS_OUTSIDE_LABELS } from "common/models/imoveisCompass.model";

type Blocks = NonNullable<ImoveisCompassData["blocks"]>;
const n = (v: number) => v.toLocaleString("pt-PT");
const pct = (v: number) => `${v > 0 ? "+" : v < 0 ? "−" : ""}${Math.abs(v).toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`;

/** Há quanto tempo estão em carteira (venda: limiar de 90 dias; arrendamento: 45). */
export function ImoveisAgingCard({ block }: { block: Blocks["aging"] }) {
    return (
        <PageCard title={block.headline} data-testid="imoveis-aging">
            <div className="p-3">
                <div className="row g-3">
                    {block.groups.map((g) => (
                        <div key={g.group} className="col-12 col-md-6">
                            <div className="fw-medium mb-1">{g.label} <span className="text-muted fs-12">({n(g.total)}; parado a partir de {g.threshold} dias)</span></div>
                            {g.bands.map((b) => <div key={b.label} className="d-flex justify-content-between fs-13 border-bottom py-1"><span>{b.label}</span><span className="fw-medium">{n(b.count)}</span></div>)}
                        </div>
                    ))}
                </div>
                {block.oldest.length > 0 && <>
                    <h6 className="text-uppercase text-muted fs-12 mt-3 mb-2">Os mais antigos</h6>
                    <ul className="list-unstyled mb-0 fs-13">
                        {block.oldest.map((o) => <li key={o.property_id} className="d-flex justify-content-between gap-2 py-1"><span className="text-truncate">{o.label}</span><span className={o.stale ? "text-warning fw-medium" : "text-muted"}>{n(o.days)} dias</span></li>)}
                    </ul>
                </>}
            </div>
        </PageCard>
    );
}

/** Preço por m² face à referência do INE (só venda, só a freguesia; nunca um preço sugerido). */
export function ImoveisPriceCard({ block }: { block: Blocks["price"] }) {
    const outside = Object.entries(block.outside);
    return (
        <PageCard title={block.headline} data-testid="imoveis-price" className="mb-3 h-100">
            <div className="p-3">
                <div className="d-flex flex-wrap gap-2 mb-3">
                    <Badge color="success-subtle" className="text-success fw-normal fs-12">Abaixo: {n(block.counts.below)}</Badge>
                    <Badge color="info-subtle" className="text-info fw-normal fs-12">Alinhados: {n(block.counts.aligned)}</Badge>
                    <Badge color="warning-subtle" className="text-warning fw-normal fs-12">Acima: {n(block.counts.above)}</Badge>
                    <span className="text-muted fs-12 align-self-center">de {n(block.eligible)} com referência da freguesia</span>
                </div>
                {block.top_above.map((r) => (
                    <div key={r.property_id} className="d-flex justify-content-between gap-2 fs-13 border-bottom py-1">
                        <span className="text-truncate">{r.label}</span>
                        <span className="text-nowrap">{pct(r.diff_pct)} <span className="text-muted">({n(r.price_m2)} face a {n(r.median)} €/m²)</span></span>
                    </div>
                ))}
                {outside.length > 0 && <p className="text-muted fs-12 mt-2 mb-0">Sem comparação: {outside.map(([k, v]) => `${IMOVEIS_OUTSIDE_LABELS[k] ?? k} (${v})`).join(", ")}.</p>}
                {block.attribution && <p className="text-muted fs-12 mt-2 mb-0" data-testid="imoveis-ine-attribution">{block.attribution}</p>}
            </div>
        </PageCard>
    );
}

export function ImoveisZonesCard({ block }: { block: Blocks["zones"] }) {
    return (
        <PageCard title={block.headline} data-testid="imoveis-zones" className="mb-3 h-100">
            <div className="p-3">
                {block.rows.length === 0 ? <p className="text-muted mb-0">Sem imóveis com freguesia.</p> : block.rows.map((z) => (
                    <div key={z.parish_id} className="d-flex justify-content-between gap-2 fs-13 border-bottom py-1">
                        <span className="text-truncate">{z.label}{z.municipality ? <span className="text-muted">, {z.municipality}</span> : null}</span>
                        <span className="text-nowrap">{n(z.properties)} {z.properties === 1 ? "imóvel" : "imóveis"}{z.median ? <span className="text-muted"> · INE {n(z.median)} €/m²</span> : null}
                            {!z.posted_30 && <Badge color="light" className="text-muted fw-normal ms-1">sem publicação em 30 dias</Badge>}</span>
                    </div>
                ))}
            </div>
        </PageCard>
    );
}

export function ImoveisInterestCard({ block }: { block: Blocks["interest"] }) {
    return (
        <PageCard title={block.headline} data-testid="imoveis-interest" className="mb-3 h-100">
            <div className="p-3">
                <p className="text-muted fs-12">{block.note}</p>
                {block.rows.length === 0 ? <p className="text-muted mb-0">Ainda sem contagens.</p> : block.rows.map((r) => (
                    <div key={r.property_id} className="d-flex justify-content-between gap-2 fs-13 border-bottom py-1">
                        <span className="text-truncate">{r.label}</span>
                        <span className="text-nowrap">{n(r.contacts)} contactos · {n(r.visits)} visitas{r.updated_days !== null ? <span className="text-muted"> · há {r.updated_days} dias</span> : null}</span>
                    </div>
                ))}
            </div>
        </PageCard>
    );
}

export function ImoveisClosedCard({ block, forgotten }: { block: Blocks["closed"]; forgotten: Blocks["forgotten"] }) {
    return (
        <PageCard title={block.headline} data-testid="imoveis-closed" className="mb-3 h-100">
            <div className="p-3">
                {block.rows.length === 0 ? <p className="text-muted">Nenhum fecho nos últimos 30 dias.</p> : block.rows.map((r) => (
                    <div key={r.property_id} className="d-flex justify-content-between gap-2 fs-13 border-bottom py-1">
                        <span className="text-truncate">{r.label}</span>
                        <span className="text-nowrap">há {r.closed_days} dias {r.testimonial ? <Badge color="success-subtle" className="text-success fw-normal">testemunho publicado</Badge> : <Badge color="light" className="text-muted fw-normal">sem testemunho</Badge>}</span>
                    </div>
                ))}
                <h6 className="text-uppercase text-muted fs-12 mt-3 mb-2">{forgotten.headline} ({n(forgotten.total)})</h6>
                {forgotten.items.map((f) => <div key={f.property_id} className="d-flex justify-content-between fs-13 py-1"><span className="text-truncate">{f.label}</span><span className="text-muted">{n(f.days)} dias</span></div>)}
            </div>
        </PageCard>
    );
}
