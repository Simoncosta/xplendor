import { Link } from "react-router-dom";
import { Badge, Col, Progress, Row, Table } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import HiddenMoney from "Components/Common/HiddenMoney";
import { useAccess } from "contexts/ModulesContext";
import { fmtPct } from "common/models/bussola.model";
import { AdRow, fmtEuro, OUTSIDE_LABELS, StandCompassData } from "common/models/standCompass.model";

/**
 * Bússola do stand, os blocos (cada um com uma manchete descritiva): stock a envelhecer, preço
 * face ao mercado, o que os anúncios estão a render, os mais procurados e os esquecidos; o
 * rodapé num cartão branco. Os veículos abrem a sua ficha só para quem vê a área automóvel.
 */
type Blocks = NonNullable<StandCompassData["blocks"]>;
const STACK = ["success", "info", "warning", "danger"];

function Headline({ text }: { text: string }) {
    return <p className="fs-14 fw-medium mb-3">{text}</p>;
}

function Vehicle({ id, label }: { id: number; label: string }) {
    const { can, has } = useAccess();
    return has("stock") && can("automovel.ver") ? <Link to={`/cars/${id}/ficha`} className="fw-medium">{label}</Link> : <span className="fw-medium">{label}</span>;
}

export function AgingCard({ block }: { block: Blocks["aging"] }) {
    return (
        <PageCard data-testid="stand-aging" title="Stock a envelhecer" flush={false} info="Os veículos à venda, pelas faixas de dias do seu tipo (um terço, dois terços e quatro terços do limiar).">
            <Headline text={block.headline} />
            <Row className="g-4">
                <Col lg={6}>
                    {block.groups.map((g) => (
                        <div key={g.group} className="mb-3">
                            <h6 className="fs-13 mb-2">{g.label} <span className="text-muted fw-normal">({g.total} à venda; limiar de {g.threshold} dias)</span></h6>
                            <Progress multi className="progress-lg mb-2" aria-label={`Faixas de dias: ${g.label}`}>
                                {g.bands.filter((b) => b.count > 0).map((b, i) => <Progress bar key={b.label} value={(b.count / Math.max(1, g.total)) * 100} color={STACK[g.bands.indexOf(b)] ?? STACK[i]} title={`${b.label}: ${b.count}`} />)}
                            </Progress>
                            <ul className="list-inline mb-0 fs-12">
                                {g.bands.map((b, i) => (
                                    <li key={b.label} className="list-inline-item me-3 mb-1"><i className={`ri-checkbox-blank-circle-fill text-${STACK[i]} me-1 align-middle`} aria-hidden />{b.label} <span className="fw-medium">{b.count}</span></li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </Col>
                <Col lg={6}>
                    <h6 className="fs-13 mb-2">Os mais antigos</h6>
                    <ul className="list-unstyled vstack gap-2 mb-0 fs-13">
                        {block.oldest.map((o) => (
                            <li key={o.car_id} className="d-flex justify-content-between gap-2">
                                <Vehicle id={o.car_id} label={o.label} />
                                <span className={`text-nowrap ${o.stale ? "text-warning fw-medium" : "text-muted"}`}>{o.days ?? "?"} dias</span>
                            </li>
                        ))}
                    </ul>
                </Col>
            </Row>
        </PageCard>
    );
}

export function PriceCard({ block }: { block: Blocks["price"] }) {
    const outside = Object.entries(block.outside).filter(([, n]) => (n ?? 0) > 0);
    return (
        <PageCard className="h-100" data-testid="stand-price" title="Preço face ao mercado" flush={false} info="Só com comparação exata e confiança média ou alta. A Bússola nunca sugere um preço.">
            <Headline text={block.headline} />
            <div className="d-flex flex-wrap gap-2 mb-3">
                <Badge color="success-subtle" className="text-success fw-normal fs-12">Abaixo: {block.counts.below}</Badge>
                <Badge color="info-subtle" className="text-info fw-normal fs-12">Alinhado: {block.counts.aligned}</Badge>
                <Badge color="warning-subtle" className="text-warning fw-normal fs-12">Acima: {block.counts.above}</Badge>
            </div>
            {block.top_above.length > 0 && (
                <div className="table-responsive">
                    <Table className="table-sm table-borderless align-middle mb-0 fs-13">
                        <thead className="text-muted"><tr><th>Veículo</th><th className="text-end">Acima</th><th className="text-end">Preço</th><th className="text-end">Mediana</th></tr></thead>
                        <tbody>
                            {block.top_above.map((r) => (
                                <tr key={r.car_id}>
                                    <td><Vehicle id={r.car_id} label={r.label} /></td>
                                    <td className="text-end text-warning fw-medium">+{fmtPct(r.diff_pct)}</td>
                                    <td className="text-end text-nowrap">{fmtEuro(r.price)}</td>
                                    <td className="text-end text-nowrap">{fmtEuro(r.median)} <span className="text-muted">({r.comparables})</span></td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            )}
            {outside.length > 0 && (
                <p className="text-muted fs-12 mt-3 mb-0" data-testid="stand-price-outside">
                    <i className="ri-information-line me-1" aria-hidden />Fora das comparações: {outside.map(([k, n]) => `${n} ${OUTSIDE_LABELS[k] ?? k}`).join(", ")}.
                </p>
            )}
        </PageCard>
    );
}

function Money({ row, field, seed }: { row: AdRow; field: "spend" | "cost_per_contact"; seed: number }) {
    if (row.hidden) return <HiddenMoney seed={seed} />;
    return <>{row[field] === null ? "Sem contactos" : fmtEuro(row[field])}</>;
}

export function AdsCard({ block }: { block: Blocks["ads"] }) {
    return (
        <PageCard className="h-100" data-testid="stand-ads" title="O que os anúncios estão a render" flush={false}
            info="Últimas 4 semanas. Gasto dos anúncios com a etiqueta [id:N] do veículo; contactos: WhatsApp, chamadas e telefone visto ou copiado no site do stand.">
            <Headline text={block.headline} />
            {block.rows.length > 0 && (
                <div className="table-responsive">
                    <Table className="table-sm table-borderless align-middle mb-0 fs-13">
                        <thead className="text-muted"><tr><th>Veículo</th><th className="text-end">Gasto</th><th className="text-end">Contactos</th><th className="text-end">Por contacto</th></tr></thead>
                        <tbody>
                            {block.rows.map((r, i) => (
                                <tr key={r.car_id}>
                                    <td><Vehicle id={r.car_id} label={r.label} />{r.sold && <Badge color="light" className="text-body fw-normal ms-2">Vendido</Badge>}</td>
                                    <td className="text-end text-nowrap"><Money row={r} field="spend" seed={i} /></td>
                                    <td className="text-end">{r.contacts}</td>
                                    <td className="text-end text-nowrap"><Money row={r} field="cost_per_contact" seed={i + 3} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            )}
        </PageCard>
    );
}

export function DemandCard({ block }: { block: Blocks["demand"] }) {
    const max = Math.max(1, ...block.rows.map((r) => r.views));
    return (
        <PageCard className="h-100" data-testid="stand-demand" title="Os mais procurados" flush={false} info="Vistas e contactos das últimas 4 semanas, no site do stand.">
            <Headline text={block.headline} />
            <div className="vstack gap-2">
                {block.rows.map((r) => (
                    <div key={r.car_id}>
                        <div className="d-flex justify-content-between gap-2 fs-13 mb-1">
                            <Vehicle id={r.car_id} label={r.label} />
                            <span className="text-nowrap">{r.views.toLocaleString("pt-PT")} vistas, {r.contacts} {r.contacts === 1 ? "contacto" : "contactos"}</span>
                        </div>
                        <Progress value={(r.views / max) * 100} color="primary" className="progress-sm" aria-label={`Vistas: ${r.label}`} />
                    </div>
                ))}
            </div>
        </PageCard>
    );
}

export function ForgottenCard({ block }: { block: Blocks["forgotten"] }) {
    return (
        <PageCard className="h-100" data-testid="stand-forgotten" title="Esquecidos" flush={false} info="À venda há mais de 30 dias, sem anúncio ativo, sem gasto e sem publicação da Bússola nos últimos 30 dias.">
            <Headline text={block.headline} />
            <ul className="list-unstyled vstack gap-2 mb-0 fs-13">
                {block.items.map((it) => (
                    <li key={it.car_id} className="d-flex justify-content-between gap-2"><Vehicle id={it.car_id} label={it.label} /><span className="text-muted text-nowrap">{it.days ?? "?"} dias</span></li>
                ))}
            </ul>
            {block.total > block.items.length && <p className="text-muted fs-12 mt-2 mb-0">E mais {block.total - block.items.length}.</p>}
        </PageCard>
    );
}

export function FooterCard({ lines }: { lines: string[] }) {
    return (
        <PageCard data-testid="stand-footer" title="Como ler a Bússola" flush={false}>
            <ul className="ps-3 mb-0 fs-13 text-muted vstack gap-1">{lines.map((l, i) => <li key={i}>{l}</li>)}</ul>
        </PageCard>
    );
}
