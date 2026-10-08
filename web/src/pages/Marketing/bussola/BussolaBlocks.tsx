import { Fragment, useMemo, useState } from "react";
import { Badge, Button, Card, CardBody, CardHeader, Col, Progress, Row, Table } from "reactstrap";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import HeatmapCard from "pages/Restauracao/HeatmapCard";
import { ChangeRow, CompassData, fmtDm, fmtEur, fmtPct } from "common/models/bussola.model";

/**
 * Bússola, os blocos (cada um com uma manchete descritiva e o seu visual, com os componentes
 * do Velzon): dias para encher (grelha dia × turno, ou o mapa por hora), as estrelas da casa
 * (barra empilhada e os 3 primeiros), a ganhar e a perder força, quando o cliente decide, por
 * onde chegam, os esquecidos e o rodapé.
 */
type Blocks = NonNullable<CompassData["blocks"]>;

const WEEKDAYS: [string, string][] = [["1", "Seg"], ["2", "Ter"], ["3", "Qua"], ["4", "Qui"], ["5", "Sex"], ["6", "Sáb"], ["7", "Dom"]];
const SHIFT_LABEL: Record<string, string> = { almoco: "Almoço", tarde: "Tarde", jantar: "Jantar", dia: "Dia" };
const STACK = ["primary", "info", "success", "warning", "danger", "secondary"];

function Headline({ text }: { text: string | null | undefined }) {
    return text ? <p className="fs-14 fw-medium mb-3">{text}</p> : null;
}

/** Barra empilhada (Progress multi do Velzon) com legenda por baixo. */
function StackedBar({ parts, label }: { parts: { label: string; pct: number; color: string }[]; label: string }) {
    return (
        <>
            <Progress multi className="progress-lg mb-2" aria-label={label}>
                {parts.filter((p) => p.pct > 0).map((p, i) => <Progress bar key={i} value={p.pct} color={p.color} title={`${p.label}: ${fmtPct(p.pct)}`} />)}
            </Progress>
            <ul className="list-inline mb-0 fs-12">
                {parts.map((p, i) => (
                    <li key={i} className="list-inline-item me-3 mb-1">
                        <i className={`ri-checkbox-blank-circle-fill text-${p.color} me-1 align-middle`} aria-hidden />{p.label} <span className="fw-medium">{fmtPct(p.pct)}</span>
                    </li>
                ))}
            </ul>
        </>
    );
}

// ── Dias para encher ─────────────────────────────────────────────────────────

export function DaysCard({ companyId, block }: { companyId: number; block: Blocks["days"] }) {
    const [view, setView] = useState<"shift" | "hour">("shift");
    const [store, setStore] = useState<number | null>(block.grids[0]?.location_id ?? null);
    const first = block.grids[0];
    return (
        <Card data-testid="bussola-days">
            <CardHeader className="d-flex flex-wrap align-items-center gap-2">
                <h5 className="card-title mb-0 flex-grow-1">Dias para encher</h5>
                <div className="xp-seg" role="radiogroup" aria-label="Vista">
                    <button type="button" role="radio" aria-checked={view === "shift"} className={view === "shift" ? "on" : ""} onClick={() => setView("shift")}>Por turno</button>
                    <button type="button" role="radio" aria-checked={view === "hour"} className={view === "hour" ? "on" : ""} onClick={() => setView("hour")}>Por hora</button>
                </div>
            </CardHeader>
            <CardBody>
                <Headline text={block.headline} />
                {view === "shift" ? (
                    block.grids.length === 0 ? <p className="text-muted mb-0">Ainda não há 6 semanas de vendas para comparar os dias.</p> : (
                        <div className="vstack gap-4">
                            {block.grids.map((g) => (
                                <div key={g.location_id}>
                                    {block.grids.length > 1 && <h6 className="fs-13 mb-2">{g.name}</h6>}
                                    <div className="table-responsive">
                                        <Table className="table-borderless table-nowrap align-middle text-center mb-0 fs-12" style={{ borderCollapse: "separate", borderSpacing: 3 }}>
                                            <thead className="text-muted">
                                                <tr>
                                                    <th className="text-start fw-medium">Turno</th>
                                                    {WEEKDAYS.map(([, l]) => <th key={l} className="fw-medium">{l}</th>)}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {Object.entries(g.shifts).map(([shift, row]) => (
                                                    <tr key={shift}>
                                                        <th className="text-start fw-medium">
                                                            {SHIFT_LABEL[shift] ?? shift}
                                                            <span className="d-block text-muted fw-normal">média {fmtEur(row.mean_cents)}</span>
                                                        </th>
                                                        {WEEKDAYS.map(([wd]) => {
                                                            const c = row.days[wd];
                                                            if (!c || c.closed) return <td key={wd} className="text-muted">Fechado</td>;
                                                            return (
                                                                <td key={wd} className={`rounded ${c.weak ? "bg-warning-subtle" : "bg-light"}`} title={c.weak ? "Período fraco" : undefined}>
                                                                    <span className="d-block fw-medium">{fmtEur(c.avg_cents)}</span>
                                                                    <span className={c.weak ? "text-warning fw-semibold" : "text-muted"}>{fmtPct(c.pct_vs_mean, true)}</span>
                                                                </td>
                                                            );
                                                        })}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </Table>
                                    </div>
                                </div>
                            ))}
                            {first && (
                                <p className="text-muted fs-12 mb-0">
                                    <span className="badge bg-warning-subtle text-warning me-1">Fraco</span>
                                    20% ou mais abaixo da média do turno. Média das vendas sem IVA de {fmtDm(first.from)} a {fmtDm(first.to)} (8 semanas){first.special_excluded > 0 ? `, sem ${first.special_excluded} dias especiais` : ""}, a mesma janela das jogadas.
                                </p>
                            )}
                        </div>
                    )
                ) : (
                    <>
                        {block.grids.length > 1 && (
                            <div className="xp-seg mb-3" role="radiogroup" aria-label="Loja do mapa">
                                {block.grids.map((g) => (
                                    <button key={g.location_id} type="button" role="radio" aria-checked={store === g.location_id} className={store === g.location_id ? "on" : ""} onClick={() => setStore(g.location_id)}>{g.name}</button>
                                ))}
                            </div>
                        )}
                        <HeatmapCard companyId={companyId} weeks={8} excludeSpecial embedded locationId={store} />
                    </>
                )}
            </CardBody>
        </Card>
    );
}

// ── As estrelas da casa ──────────────────────────────────────────────────────

export function StarsCard({ block }: { block: Blocks["stars"] }) {
    const [all, setAll] = useState(false);
    if (block.stores.length === 0) return null;
    return (
        <Card data-testid="bussola-stars">
            <CardHeader className="d-flex align-items-center">
                <h5 className="card-title mb-0 flex-grow-1">As estrelas da casa</h5>
                <Button color="outline-primary" size="sm" onClick={() => setAll((v) => !v)}>{all ? "Ver só os 3 primeiros" : "Ver o top 10"}</Button>
            </CardHeader>
            <CardBody>
                <Headline text={block.headline} />
                <Row className="g-4">
                    {block.stores.map((s) => (
                        <Col key={s.location_id} lg={block.stores.length > 1 ? 6 : 12}>
                            {block.stores.length > 1 && <h6 className="fs-13 mb-2">{s.name}</h6>}
                            <StackedBar label={`Peso dos 3 artigos mais vendidos em ${s.name}`} parts={[
                                ...s.items.slice(0, 3).map((it, i) => ({ label: it.name, pct: it.share_pct, color: STACK[i] })),
                                { label: "Outros artigos", pct: s.others_pct, color: "light" },
                            ]} />
                            <ul className="list-group list-group-flush border-dashed mt-2 mb-0">
                                {s.items.slice(0, all ? 10 : 3).map((it, i) => (
                                    <li key={it.product_id} className="list-group-item d-flex align-items-center px-0">
                                        <div className="avatar-xs flex-shrink-0">
                                            <span className={`avatar-title rounded-circle fs-12 ${i < 3 ? `bg-${STACK[i]}-subtle text-${STACK[i]}` : "bg-light text-muted"}`}>{i + 1}</span>
                                        </div>
                                        <div className="flex-grow-1 ms-3 min-w-0">
                                            <h6 className="fs-13 mb-0 text-truncate">{it.name}</h6>
                                            <p className="text-muted fs-12 mb-0">{it.qty.toLocaleString("pt-PT")} unidades</p>
                                        </div>
                                        <div className="flex-shrink-0 text-end">
                                            <h6 className="fs-13 mb-0">{fmtEur(it.net_cents)}</h6>
                                            <p className="text-muted fs-12 mb-0">{fmtPct(it.share_pct)}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Col>
                    ))}
                </Row>
                <p className="text-muted fs-12 mt-3 mb-0">Valor sem IVA das últimas 4 semanas. Sem as categorias "Excluir" e "Entrega".</p>
            </CardBody>
        </Card>
    );
}

// ── A ganhar e a perder força ────────────────────────────────────────────────

/** Ignorar durante 4 semanas, ou não voltar a sugerir o artigo (permanente). */
export type IgnoreFn = (key: string, permanent: boolean, name: string) => void;

const ignoreItems = (key: string, name: string, onIgnore: IgnoreFn) => [
    { label: "Ignorar durante 4 semanas", icon: "ri-eye-off-line", onClick: () => onIgnore(key, false, name) },
    { label: "Não voltar a sugerir este artigo", icon: "ri-forbid-2-line", onClick: () => onIgnore(key, true, name) },
];

function ChangeList({ title, rows, visible, up, canAct, onIgnore }: { title: string; rows: ChangeRow[]; visible: number; up: boolean; canAct: boolean; onIgnore: IgnoreFn }) {
    const [more, setMore] = useState(false);
    const shown = more ? rows : rows.slice(0, visible);
    const groups = useMemo(() => {
        const map = new Map<string, ChangeRow[]>();
        shown.forEach((r) => map.set(r.category, [...(map.get(r.category) ?? []), r]));
        return Array.from(map.entries());
    }, [shown]);
    return (
        <div>
            <h6 className="fs-13 mb-2"><i className={`${up ? "ri-arrow-right-up-line text-success" : "ri-arrow-right-down-line text-danger"} me-1`} aria-hidden />{title} ({rows.length})</h6>
            {rows.length === 0 ? <p className="text-muted fs-13 mb-0">Nenhum artigo.</p> : (
                <>
                    {groups.map(([cat, list]) => (
                        <Fragment key={cat}>
                            <p className="text-uppercase text-muted fw-semibold fs-11 mt-2 mb-1">{cat}</p>
                            <ul className="list-group list-group-flush border-dashed mb-0">
                                {list.map((r) => (
                                    <li key={r.key} className="list-group-item d-flex align-items-center gap-2 px-0 py-2">
                                        <div className="flex-grow-1 min-w-0">
                                            <span className="fs-13 d-block text-truncate">{r.name}</span>
                                            <span className="text-muted fs-12">{r.location}{r.confidence === "media" ? ", confiança média" : ""}</span>
                                        </div>
                                        <span className="text-muted fs-12 text-nowrap">{r.before.toLocaleString("pt-PT")} → {r.now.toLocaleString("pt-PT")} un.</span>
                                        <Badge color={up ? "success-subtle" : "danger-subtle"} className={`text-${up ? "success" : "danger"} fw-medium`} style={{ minWidth: 52 }}>{fmtPct(r.variation_pct, true)}</Badge>
                                        <ActionsMenu size="sm" label={`Mais ações: ${r.name}`} disabled={!canAct} items={ignoreItems(r.key, r.name, onIgnore)} />
                                    </li>
                                ))}
                            </ul>
                        </Fragment>
                    ))}
                    {rows.length > visible && (
                        <Button color="outline-primary" size="sm" className="mt-2" onClick={() => setMore((v) => !v)}>{more ? "Ver menos" : `Ver mais ${rows.length - visible}`}</Button>
                    )}
                </>
            )}
        </div>
    );
}

export function ChangesCard({ block, canAct, onIgnore }: { block: Blocks["changes"]; canAct: boolean; onIgnore: IgnoreFn }) {
    const ctx = block.context;
    return (
        <Card data-testid="bussola-changes">
            <CardHeader><h5 className="card-title mb-0">A ganhar e a perder força</h5></CardHeader>
            <CardBody>
                <Headline text={block.headline} />
                <div className="bg-light rounded p-3 fs-13 mb-3" data-testid="changes-context">
                    <p className="mb-1">Unidades vendidas nas últimas 4 semanas ({fmtDm(ctx.period_now.from)} a {fmtDm(ctx.period_now.to)}) contra as 4 anteriores ({fmtDm(ctx.period_before.from)} a {fmtDm(ctx.period_before.to)}).</p>
                    <p className="mb-1">Vendas de cada loja no mesmo período: {ctx.stores.map((s) => `${s.name} ${fmtPct(s.variation_pct, true)}`).join("; ")}. Só entram artigos que mudaram mais 20 pontos do que a própria loja.</p>
                    {ctx.special_days.length > 0 && <p className="mb-0 text-muted">Inclui datas especiais: {ctx.special_days.join(", ")}.</p>}
                </div>
                <Row className="g-4">
                    <Col md={6}><ChangeList title="A ganhar força" rows={block.up} visible={block.visible} up canAct={canAct} onIgnore={onIgnore} /></Col>
                    <Col md={6}><ChangeList title="A perder força" rows={block.down} visible={block.visible} up={false} canAct={canAct} onIgnore={onIgnore} /></Col>
                </Row>
            </CardBody>
        </Card>
    );
}

// ── Quando o cliente decide ──────────────────────────────────────────────────

export function DecideCard({ block }: { block: Blocks["decide"] }) {
    if (block.stores.length === 0) return null;
    return (
        <Card className="h-100" data-testid="bussola-decide">
            <CardHeader><h5 className="card-title mb-0">Quando o cliente decide</h5></CardHeader>
            <CardBody>
                <div className="d-flex align-items-center gap-3 mb-3">
                    <div className="avatar-sm flex-shrink-0"><span className="avatar-title bg-warning-subtle text-warning rounded-circle fs-2"><i className="ri-time-line" aria-hidden /></span></div>
                    <div>
                        <h2 className="ff-secondary fw-semibold mb-0">{fmtPct(block.same_day_pct)}</h2>
                        <p className="text-muted mb-0 fs-13">das reservas são feitas no próprio dia</p>
                    </div>
                </div>
                <Headline text={block.headline} />
                <div className="vstack gap-3">
                    {block.stores.map((s) => (
                        <div key={s.name}>
                            {block.stores.length > 1 && <h6 className="fs-13 mb-2">{s.name} <span className="text-muted fw-normal">({s.total.toLocaleString("pt-PT")} reservas)</span></h6>}
                            <StackedBar label={`Antecedência das reservas em ${s.name}`} parts={s.buckets.map((b, i) => ({ label: b.label, pct: b.pct, color: STACK[i] }))} />
                        </div>
                    ))}
                </div>
                <p className="text-muted fs-12 mt-3 mb-0">Últimos 90 dias de reservas no CoverManager, sem as entradas sem reserva.</p>
            </CardBody>
        </Card>
    );
}

// ── Por onde chegam ──────────────────────────────────────────────────────────

export function ChannelsCard({ block }: { block: Blocks["channels"] }) {
    if (block.stores.length === 0) return null;
    return (
        <Card className="h-100" data-testid="bussola-channels">
            <CardHeader><h5 className="card-title mb-0">Por onde chegam</h5></CardHeader>
            <CardBody>
                <Headline text={block.headline} />
                <div className="vstack gap-3">
                    {block.stores.map((s) => (
                        <div key={s.name}>
                            <h6 className="fs-13 mb-2">{s.name} <span className="text-muted fw-normal">({s.total.toLocaleString("pt-PT")} reservas)</span></h6>
                            <StackedBar label={`Canais de reserva em ${s.name}`} parts={s.channels.map((c, i) => ({ label: c.label, pct: c.pct, color: STACK[i % STACK.length] }))} />
                        </div>
                    ))}
                </div>
                <p className="text-muted fs-12 mt-3 mb-0">Últimos 90 dias. "Sem reserva" são as entradas registadas à porta.</p>
            </CardBody>
        </Card>
    );
}

// ── Esquecidos ───────────────────────────────────────────────────────────────

export function ForgottenCard({ block, canAct, canActReason, onCreate, onIgnore }: {
    block: Blocks["forgotten"]; canAct: boolean; canActReason?: string | null;
    onCreate: (item: Blocks["forgotten"]["items"][number]) => void; onIgnore: IgnoreFn;
}) {
    return (
        <Card data-testid="bussola-forgotten">
            <CardHeader><h5 className="card-title mb-0">Esquecidos</h5></CardHeader>
            <CardBody>
                <Headline text={block.headline} />
                {block.items.length > 0 && (
                    <ul className="list-group list-group-flush border-dashed d-md-none mb-0" data-testid="forgotten-list">
                        {block.items.map((it) => (
                            <li key={it.key} className="list-group-item px-0">
                                <div className="d-flex justify-content-between gap-2">
                                    <span className="fw-medium">{it.name}</span>
                                    <span className="text-muted fs-12 text-nowrap">sem vendas há {it.days} dias</span>
                                </div>
                                <div className="text-muted fs-12 mb-2">{it.location}, {it.qty_before.toLocaleString("pt-PT")} un. nas 8 semanas anteriores</div>
                                <div className="d-flex gap-2">
                                    <ReasonButton color="outline-primary" size="sm" onClick={() => onCreate(it)} reason={canAct ? null : canActReason || "Sem permissão."}>Criar publicação</ReasonButton>
                                    <ActionsMenu size="sm" label={`Mais ações: ${it.name}`} disabled={!canAct} items={ignoreItems(it.key, it.name, onIgnore)} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {block.items.length > 0 && (
                    <div className="table-responsive table-card d-none d-md-block">
                        <Table className="table-hover table-centered align-middle table-nowrap mb-0 fs-13">
                            <thead className="text-muted table-light">
                                <tr><th>Artigo</th><th>Loja</th><th className="text-end">Sem vendas há</th><th className="text-end">Vendidos antes</th><th /></tr>
                            </thead>
                            <tbody>
                                {block.items.map((it) => (
                                    <tr key={it.key}>
                                        <td className="fw-medium">{it.name}</td>
                                        <td>{it.location}</td>
                                        <td className="text-end">{it.days} dias</td>
                                        <td className="text-end">{it.qty_before.toLocaleString("pt-PT")} un. em 8 semanas</td>
                                        <td className="text-end">
                                            <div className="d-inline-flex gap-2">
                                                <ReasonButton color="outline-primary" size="sm" onClick={() => onCreate(it)} reason={canAct ? null : canActReason || "Sem permissão."}>Criar publicação</ReasonButton>
                                                <ActionsMenu size="sm" label={`Mais ações: ${it.name}`} disabled={!canAct} items={ignoreItems(it.key, it.name, onIgnore)} />
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </CardBody>
        </Card>
    );
}

// ── Rodapé ───────────────────────────────────────────────────────────────────

export function BussolaFooter({ computedAt }: { computedAt: string | null }) {
    return (
        <Card data-testid="bussola-footer"><CardBody className="text-muted fs-12">
            <p className="mb-1">Os números descrevem o que aconteceu; não dizem porquê.</p>
            <p className="mb-1">
                <Badge color="success-subtle" className="text-success fw-normal me-1">Confiança alta</Badge> amostra grande e desvio claro.{" "}
                <Badge color="info-subtle" className="text-info fw-normal mx-1">Confiança média</Badge> amostra mais pequena ou desvio menor. Os sinais de confiança baixa não aparecem.
            </p>
            {computedAt && <p className="mb-0">Calculado a {new Date(computedAt).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}. Recalcula-se todas as noites e quando as categorias são confirmadas.</p>}
        </CardBody></Card>
    );
}
