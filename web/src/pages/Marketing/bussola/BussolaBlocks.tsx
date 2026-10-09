import { Fragment, useMemo, useState } from "react";
import { Badge, Button, Col, Progress, Row, Table } from "reactstrap";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import HeatmapCard from "pages/Restauracao/HeatmapCard";
import { ChangeRow, CompassData, fmtDm, fmtEur, fmtPct } from "common/models/bussola.model";

/**
 * Bússola, os blocos (cada um com uma manchete descritiva e o seu visual, com os componentes
 * do Velzon): dias para encher (grelha dia × turno, ou o mapa por hora), as estrelas da casa
 * (barra empilhada e os 3 primeiros), a ganhar e a perder força, quando o cliente decide, por
 * onde chegam e os esquecidos (o rodapé passou para o (i) do PageHeader).
 *
 * UI-2a: cada bloco é um PageCard; as notas de rodapé passaram para o (i) do título e os
 * esquecidos para um DataTable.
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
        <PageCard
            data-testid="bussola-days"
            title="Dias para encher"
            flush={false}
            info={first ? <>Fraco: 20% ou mais abaixo da média do turno. Média das vendas sem IVA de {fmtDm(first.from)} a {fmtDm(first.to)} (8 semanas){first.special_excluded > 0 ? `, sem ${first.special_excluded} dias especiais` : ""}, a mesma janela das jogadas.</> : undefined}
            actions={
                <div className="xp-seg" role="radiogroup" aria-label="Vista">
                    <button type="button" role="radio" aria-checked={view === "shift"} className={view === "shift" ? "on" : ""} onClick={() => setView("shift")}>Por turno</button>
                    <button type="button" role="radio" aria-checked={view === "hour"} className={view === "hour" ? "on" : ""} onClick={() => setView("hour")}>Por hora</button>
                </div>
            }
        >
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
        </PageCard>
    );
}

// ── As estrelas da casa ──────────────────────────────────────────────────────

export function StarsCard({ block }: { block: Blocks["stars"] }) {
    const [all, setAll] = useState(false);
    if (block.stores.length === 0) return null;
    return (
        <PageCard
            data-testid="bussola-stars"
            title="As estrelas da casa"
            flush={false}
            info='Valor sem IVA das últimas 4 semanas, sem as categorias "Excluir" e "Entrega".'
            actions={<Button color="outline-primary" size="sm" onClick={() => setAll((v) => !v)}>{all ? "Ver só os 3 primeiros" : "Ver o top 10"}</Button>}
        >
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
        </PageCard>
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
        <PageCard
            data-testid="bussola-changes"
            title="A ganhar e a perder força"
            flush={false}
            info={<>Unidades vendidas nas últimas 4 semanas contra as 4 anteriores. Só entram artigos que mudaram mais 20 pontos do que a própria loja.{ctx.special_days.length > 0 && <> Inclui datas especiais: {ctx.special_days.join(", ")}.</>}</>}
            status={<span data-testid="changes-context">{fmtDm(ctx.period_now.from)} a {fmtDm(ctx.period_now.to)} contra {fmtDm(ctx.period_before.from)} a {fmtDm(ctx.period_before.to)} · Vendas de cada loja: {ctx.stores.map((s) => `${s.name} ${fmtPct(s.variation_pct, true)}`).join("; ")}</span>}
        >
                <Headline text={block.headline} />
                <Row className="g-4">
                    <Col md={6}><ChangeList title="A ganhar força" rows={block.up} visible={block.visible} up canAct={canAct} onIgnore={onIgnore} /></Col>
                    <Col md={6}><ChangeList title="A perder força" rows={block.down} visible={block.visible} up={false} canAct={canAct} onIgnore={onIgnore} /></Col>
                </Row>
        </PageCard>
    );
}

// ── Quando o cliente decide ──────────────────────────────────────────────────

export function DecideCard({ block }: { block: Blocks["decide"] }) {
    if (block.stores.length === 0) return null;
    return (
        <PageCard className="h-100" data-testid="bussola-decide" title="Quando o cliente decide" flush={false}
            info="Últimos 90 dias de reservas no CoverManager, sem as entradas sem reserva.">
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
        </PageCard>
    );
}

// ── Por onde chegam ──────────────────────────────────────────────────────────

export function ChannelsCard({ block }: { block: Blocks["channels"] }) {
    if (block.stores.length === 0) return null;
    return (
        <PageCard className="h-100" data-testid="bussola-channels" title="Por onde chegam" flush={false}
            info='Últimos 90 dias. "Sem reserva" são as entradas registadas à porta.'>
                <Headline text={block.headline} />
                <div className="vstack gap-3">
                    {block.stores.map((s) => (
                        <div key={s.name}>
                            <h6 className="fs-13 mb-2">{s.name} <span className="text-muted fw-normal">({s.total.toLocaleString("pt-PT")} reservas)</span></h6>
                            <StackedBar label={`Canais de reserva em ${s.name}`} parts={s.channels.map((c, i) => ({ label: c.label, pct: c.pct, color: STACK[i % STACK.length] }))} />
                        </div>
                    ))}
                </div>
        </PageCard>
    );
}

// ── Esquecidos ───────────────────────────────────────────────────────────────

type ForgottenItem = Blocks["forgotten"]["items"][number];

export function ForgottenCard({ block, canAct, canActReason, onCreate, onIgnore }: {
    block: Blocks["forgotten"]; canAct: boolean; canActReason?: string | null;
    onCreate: (item: ForgottenItem) => void; onIgnore: IgnoreFn;
}) {
    const columns: DTColumn<ForgottenItem>[] = [
        { id: "name", header: "Artigo", value: (it) => it.name, cell: (it) => <span className="fw-medium">{it.name}</span>, mobile: "title" },
        { id: "location", header: "Loja", value: (it) => it.location, mobile: "subtitle" },
        { id: "days", header: "Sem vendas há", value: (it) => it.days, cell: (it) => `${it.days} dias`, align: "end", nowrap: true },
        { id: "before", header: "Vendidos antes", value: (it) => it.qty_before, cell: (it) => `${it.qty_before.toLocaleString("pt-PT")} un. em 8 semanas`, align: "end", nowrap: true },
    ];
    const cols = useDataColumns("bussola.esquecidos", columns);
    return (
        <PageCard data-testid="bussola-forgotten" title="Esquecidos" actions={cols.selector}>
            {block.headline && <div className="px-3 pt-3"><Headline text={block.headline} /></div>}
            <DataTable
                columns={cols}
                data={block.items}
                rowKey={(it) => it.key}
                caption="Artigos esquecidos"
                empty={{ message: "Sem artigos esquecidos." }}
                rowActions={(it) => (
                    <>
                        <ReasonButton color="outline-primary" size="sm" onClick={() => onCreate(it)} reason={canAct ? null : canActReason || "Sem permissão."}>Criar publicação</ReasonButton>
                        <ActionsMenu size="sm" label={`Mais ações: ${it.name}`} disabled={!canAct} items={ignoreItems(it.key, it.name, onIgnore)} />
                    </>
                )}
            />
        </PageCard>
    );
}
