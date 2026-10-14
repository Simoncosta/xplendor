import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge, Card, CardBody, Col, Container, Row } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import RestFilterBar from "Components/Common/RestFilterBar";
import XSelect from "Components/Common/Select";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { getAdminRadar, getAdminRadarItems, switchAdminRadarCompany } from "helpers/laravel_helper";
import { toast } from "react-toastify";
import { confirmAction } from "helpers/swal";
import type { RadarAdminCompany, RadarAdminCompanySignals, RadarAdminIne, RadarAdminSector, RadarItem, RadarItemsPage, RadarSourceSummary, RadarSummary, RadarTopicKind, RadarTopicSummary, RadarVerdict } from "common/models/radar.model";

/**
 * Administração › Radar (só o root): tudo o que entrou dos feeds públicos (Google Trends, jornais,
 * BCE), o que o filtro de segurança tirou e porquê, as tendências recentes e a última recolha de
 * cada fonte, com a atribuição e os termos. De cada item só se mostra o título, a ligação, a
 * fonte, a hora e o volume aproximado.
 */

type View = "entradas" | "retiradas";
const STATUS_LABEL: Record<string, string> = { new: "Por tratar", reacted: "Reagidos", ignored: "Ignorados", muted: "Silenciados" };
type FilterRow = { key: string; label: string; kind: "motivo" | "marca"; count: number };

const PER_PAGE = 25;

const KIND_LABEL: Record<RadarTopicKind, string> = { trend: "Pesquisas", news: "Notícias", weather: "Aviso meteorológico", bce: "BCE" };

const dmyHm = (iso: string | null) => (iso ? new Date(iso).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "");
const num = (n: number) => n.toLocaleString("pt-PT");

function VerdictBadge({ verdict }: { verdict: RadarVerdict }) {
    return verdict === "passed"
        ? <Badge color="success-subtle" className="text-success">Passou</Badge>
        : <Badge color="danger-subtle" className="text-danger">Retirado</Badge>;
}

/** Os motivos (retiram) e as marcas (passam, com aviso no cartão), com os nomes do servidor. */
function Reasons({ reasons, flags, labels }: { reasons: string[]; flags: string[]; labels: Record<string, string> }) {
    if (reasons.length === 0 && flags.length === 0) return <span className="text-muted">Nenhum</span>;
    return (
        <span className="d-inline-flex flex-wrap gap-1">
            {reasons.map((r) => <span key={r} className="badge bg-danger-subtle text-danger">{labels[r] ?? r}</span>)}
            {flags.map((f) => <span key={f} className="badge bg-warning-subtle text-warning">{labels[f] ?? f}</span>)}
        </span>
    );
}

export default function AdminRadarPage() {
    document.title = "Radar | Xplendor";
    const [summary, setSummary] = useState<RadarSummary | null>(null);
    const [loadingSummary, setLoadingSummary] = useState(true);
    const [view, setView] = useState<View>("entradas");
    const [source, setSource] = useState("");
    const [days, setDays] = useState(1);
    const [page, setPage] = useState(1);
    const [items, setItems] = useState<RadarItemsPage | null>(null);
    const [loadingItems, setLoadingItems] = useState(true);
    const [failed, setFailed] = useState(false);
    // R2: relevância por ramo, sinais por empresa e o interruptor do Radar por empresa.
    const [panel, setPanel] = useState<"relevancia" | "sinais" | "empresas" | "ine">("relevancia");
    const [switching, setSwitching] = useState<number | null>(null);

    useEffect(() => {
        getAdminRadar()
            .then((r: any) => setSummary(r?.data ?? null))
            .catch(() => setFailed(true))
            .finally(() => setLoadingSummary(false));
    }, []);

    const loadItems = useCallback(() => {
        setLoadingItems(true);
        getAdminRadarItems({ verdict: view === "retiradas" ? "blocked" : undefined, source: source || undefined, days, page, per_page: PER_PAGE })
            .then((r: any) => setItems(r?.data ?? null))
            .catch(() => { setItems(null); setFailed(true); })
            .finally(() => setLoadingItems(false));
    }, [view, source, days, page]);
    useEffect(() => { loadItems(); }, [loadItems]);
    useEffect(() => { setPage(1); }, [view, source, days]);

    const labels = useMemo(() => summary?.labels ?? items?.labels ?? {}, [summary, items]);
    const sourceOptions = useMemo(() => [{ value: "", label: "Todas as fontes" }, ...(summary?.sources ?? []).map((s) => ({ value: s.key, label: s.label }))], [summary]);
    const dayOptions = [{ value: 1, label: "Últimas 24 horas" }, { value: 7, label: "Últimos 7 dias" }, { value: 30, label: "Últimos 30 dias" }];

    const itemCols = useDataColumns<RadarItem>("admin.radar.itens", [
        { id: "source", header: "Fonte", value: (i) => i.source_name, mobile: "subtitle", nowrap: true },
        { id: "time", header: "Hora", value: (i) => i.published_at ?? i.fetched_at, cell: (i) => dmyHm(i.published_at ?? i.fetched_at), nowrap: true },
        {
            id: "title", header: "Título", value: (i) => i.title, hideable: false, mobile: "title",
            cell: (i) => <span className="text-wrap d-inline-block" style={{ maxWidth: 420, overflowWrap: "anywhere" }}>{i.title}</span>,
        },
        {
            id: "link", header: "Ligação",
            cell: (i) => (i.url
                ? <a href={i.url} target="_blank" rel="noopener noreferrer" aria-label={`Abrir na fonte: ${i.title}`}>Abrir <i className="ri-external-link-line" /></a>
                : <span className="text-muted">Sem ligação</span>),
        },
        { id: "volume", header: "Volume", align: "end", nowrap: true, value: (i) => i.approx_volume ?? undefined, cell: (i) => i.approx_volume_label ?? (i.approx_volume != null ? num(i.approx_volume) : <span className="text-muted">-</span>) },
        { id: "verdict", header: "Veredicto", value: (i) => i.verdict, cell: (i) => <VerdictBadge verdict={i.verdict} /> },
        { id: "reasons", header: "Motivos", cell: (i) => <Reasons reasons={i.reasons} flags={i.flags} labels={labels} /> },
        {
            id: "topic", header: "Tendência",
            cell: (i) => (i.topic
                ? <span className="text-wrap d-inline-block" style={{ maxWidth: 240 }}>{KIND_LABEL[i.topic.kind]}: {i.topic.title}</span>
                : <span className="text-muted">{i.source_type === "news" ? "Sem tendência" : "Nenhuma"}</span>),
        },
    ] as DTColumn<RadarItem>[]);

    const topicCols = useDataColumns<RadarTopicSummary>("admin.radar.tendencias", [
        { id: "kind", header: "Tipo", value: (t) => KIND_LABEL[t.kind], mobile: "subtitle", nowrap: true },
        { id: "title", header: "Tendência", value: (t) => t.title, hideable: false, mobile: "title", cell: (t) => <span className="text-wrap d-inline-block" style={{ maxWidth: 360, overflowWrap: "anywhere" }}>{t.title}</span> },
        { id: "since", header: "Vista desde", value: (t) => t.first_seen_at, cell: (t) => dmyHm(t.first_seen_at), nowrap: true },
        { id: "until", header: "Vale até", value: (t) => t.window_until, cell: (t) => <span className={t.open ? "" : "text-muted"}>{dmyHm(t.window_until)}{t.open ? "" : " (terminou)"}</span>, nowrap: true },
        { id: "volume", header: "Volume", align: "end", value: (t) => t.approx_volume ?? undefined, cell: (t) => t.approx_volume_label ?? (t.kind === "news" ? `${t.source_names.length} jornais` : <span className="text-muted">-</span>), nowrap: true },
        { id: "verdict", header: "Veredicto", value: (t) => t.verdict, cell: (t) => <VerdictBadge verdict={t.verdict} /> },
        { id: "reasons", header: "Motivos", cell: (t) => <Reasons reasons={t.reasons} flags={t.flags} labels={labels} /> },
        { id: "sources", header: "Fontes", value: (t) => t.source_names.join(", "), cell: (t) => <span className="text-wrap d-inline-block" style={{ maxWidth: 260 }}>{t.source_names.join(", ")}</span> },
    ] as DTColumn<RadarTopicSummary>[]);

    const sourceCols = useDataColumns<RadarSourceSummary>("admin.radar.fontes", [
        {
            id: "label", header: "Fonte", value: (s) => s.label, hideable: false, mobile: "title",
            cell: (s) => <div><div className="fw-semibold">{s.label}</div><div className="text-muted fs-12">{s.attribution}</div></div>,
        },
        {
            id: "state", header: "Estado", value: (s) => (s.enabled ? 1 : 0),
            cell: (s) => (s.enabled
                ? <Badge color="success-subtle" className="text-success">Ligada</Badge>
                : <div><Badge color="light" className="text-body">Desligada</Badge>{s.disabled_reason && <div className="text-muted fs-12 mt-1 text-wrap" style={{ maxWidth: 260 }}>{s.disabled_reason}</div>}</div>),
        },
        {
            id: "last", header: "Última recolha", value: (s) => s.last_run?.started_at ?? "",
            cell: (s) => {
                if (!s.last_run) return <span className="text-muted">Ainda não foi lida</span>;
                const r = s.last_run;
                return (
                    <div>
                        <span className="me-1">{dmyHm(r.started_at)}</span>
                        {r.status === "ok" ? <Badge color="success-subtle" className="text-success">OK</Badge> : <Badge color="danger-subtle" className="text-danger">Erro</Badge>}
                        {r.dry_run && <Badge color="light" className="text-body ms-1">Simulação</Badge>}
                        <div className="text-muted fs-12 text-wrap" style={{ maxWidth: 280 }}>
                            {r.status === "ok" ? `${r.items_seen} lidos, ${r.items_new} novos, ${r.items_blocked} retirados` : r.error}
                        </div>
                    </div>
                );
            },
        },
        { id: "requests", header: "Pedidos na última hora", align: "end", value: (s) => s.requests_last_hour, cell: (s) => `${s.requests_last_hour} de ${summary?.limits.max_requests_per_hour ?? 4}` },
        { id: "items", header: "Entradas (24 horas)", align: "end", value: (s) => s.items_24h },
        {
            id: "terms", header: "Termos",
            cell: (s) => (s.terms_url
                ? <a href={s.terms_url} target="_blank" rel="noopener noreferrer">Termos de uso <i className="ri-external-link-line" /></a>
                : <span className="text-muted">Sem ligação</span>),
        },
        { id: "note", header: "Nota sobre os termos", defaultVisible: false, value: (s) => s.terms_note ?? "", cell: (s) => <span className="text-wrap d-inline-block" style={{ maxWidth: 320 }}>{s.terms_note}</span> },
    ] as DTColumn<RadarSourceSummary>[]);

    const filterRows: FilterRow[] = useMemo(() => [
        ...(summary?.blocked_by_reason ?? []).map((r) => ({ key: r.reason, label: r.label, kind: "motivo" as const, count: r.count })),
        ...(summary?.flags ?? []).map((f) => ({ key: f.flag, label: f.label, kind: "marca" as const, count: f.count })),
    ], [summary]);
    const filterCols = useDataColumns<FilterRow>("admin.radar.filtro", [
        { id: "label", header: "Regra", value: (r) => r.label, hideable: false, mobile: "title" },
        { id: "kind", header: "O que faz", value: (r) => r.kind, cell: (r) => (r.kind === "motivo" ? "Retira" : "Passa, com aviso no cartão") },
        { id: "count", header: "Itens (30 dias)", align: "end", value: (r) => r.count },
    ] as DTColumn<FilterRow>[]);

    const toggleCompany = async (c: RadarAdminCompany) => {
        const on = !c.radar_enabled;
        if (!(await confirmAction({ title: on ? `Ligar o Radar em ${c.name}?` : `Desligar o Radar em ${c.name}?`,
            text: on ? "A empresa passa a receber até 3 sinais por dia (com RADAR_ENABLED ligado)." : "A empresa deixa de receber sinais novos.", confirmText: on ? "Ligar" : "Desligar" }))) return;
        setSwitching(c.id);
        try {
            const res: any = await switchAdminRadarCompany(c.id, on);
            toast.success(res?.message ?? "Feito.");
            setSummary((s) => (s ? { ...s, companies: res?.data?.companies ?? s.companies } : s));
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível mudar o Radar desta empresa.");
        } finally {
            setSwitching(null);
        }
    };
    const sectorCols = useDataColumns<RadarAdminSector>("admin.radar.relevancia", [
        { id: "sector", header: "Ramo", value: (r) => r.sector, hideable: false, mobile: "title" },
        { id: "evaluated", header: "Avaliadas (30 dias)", align: "end", value: (r) => r.evaluated },
        { id: "relevant", header: "Relevantes", align: "end", value: (r) => r.relevant },
        { id: "cost", header: "Custo da IA este mês", align: "end", value: (r) => r.cost_month_usd, cell: (r) => `${r.cost_month_usd.toFixed(4)} USD`, nowrap: true },
    ] as DTColumn<RadarAdminSector>[]);
    const signalCols = useDataColumns<RadarAdminCompanySignals>("admin.radar.sinais", [
        { id: "company", header: "Empresa", value: (r) => r.company ?? String(r.company_id), hideable: false, mobile: "title" },
        { id: "today", header: "Hoje", align: "end", value: (r) => r.today },
        { id: "status", header: "Por estado (30 dias)", value: (r) => Object.entries(r.by_status).map(([k, n]) => `${STATUS_LABEL[k] ?? k}: ${n}`).join(", ") },
        { id: "cost", header: "Custo da IA este mês", align: "end", value: (r) => r.cost_month_usd, cell: (r) => `${r.cost_month_usd.toFixed(4)} USD`, nowrap: true },
    ] as DTColumn<RadarAdminCompanySignals>[]);
    const companyCols = useDataColumns<RadarAdminCompany>("admin.radar.empresas", [
        { id: "name", header: "Empresa", value: (r) => r.name, hideable: false, mobile: "title" },
        { id: "sector", header: "Ramo", value: (r) => r.sector ?? "", cell: (r) => r.sector ?? <span className="text-muted">Sem ramo</span> },
        { id: "radar", header: "Radar", value: (r) => (r.radar_enabled ? 1 : 0), cell: (r) => (
            <div className="form-check form-switch mb-0">
                <input className="form-check-input" type="checkbox" role="switch" id={`radar-co-${r.id}`} checked={r.radar_enabled} disabled={switching === r.id}
                    onChange={() => void toggleCompany(r)} aria-label={`Radar em ${r.name}`} data-testid={`radar-switch-${r.id}`} />
                <label className="form-check-label fs-12" htmlFor={`radar-co-${r.id}`}>{r.radar_enabled ? "Ligado" : "Desligado"}</label>
            </div>
        ) },
    ] as DTColumn<RadarAdminCompany>[]);

    const viewToggle = (
        <div className="xp-seg" role="tablist" aria-label="Vista">
            <button type="button" role="tab" aria-selected={view === "entradas"} className={view === "entradas" ? "on" : ""} onClick={() => setView("entradas")}>Entradas</button>
            <button type="button" role="tab" aria-selected={view === "retiradas"} className={view === "retiradas" ? "on" : ""} onClick={() => setView("retiradas")}>Retiradas pelo filtro</button>
        </div>
    );

    const t = summary?.totals;
    const tiles = [
        { id: "entradas-24h", label: "Entradas (24 horas)", value: t?.items_24h ?? 0, icon: "ri-rss-line", color: "primary" },
        { id: "retiradas-24h", label: "Retiradas pelo filtro (24 horas)", value: t?.blocked_24h ?? 0, icon: "ri-shield-check-line", color: "danger" },
        { id: "entradas-30d", label: "Entradas (30 dias)", value: t?.items_30d ?? 0, icon: "ri-archive-line", color: "info", sub: t ? `${num(t.blocked_30d)} retiradas` : undefined },
        { id: "tendencias", label: "Tendências abertas", value: t?.topics_open_passed ?? 0, icon: "ri-line-chart-line", color: "success", sub: t ? `que passaram o filtro, de ${num(t.topics_open)}` : undefined },
    ];

    const pg = items?.pagination;
    const emptyMessage = view === "retiradas" ? "O filtro não retirou nada neste período." : "Ainda não entrou nada neste período.";

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Radar" breadcrumbs={[{ label: "Administração" }]}
                    info="O que entrou dos feeds públicos (Google Trends, jornais e BCE), o que o filtro de segurança tirou e porquê. De cada item guarda-se só o título, a ligação, a fonte, a hora e o volume, durante 30 dias." />

                {summary && !summary.radar_enabled && (
                    <div className="alert alert-secondary d-flex align-items-start gap-2" role="status" data-testid="radar-disabled">
                        <i className="ri-pause-circle-line fs-18 flex-shrink-0" />
                        <div><strong>Radar desligado.</strong> A recolha dos feeds, a relevância e o encaixe não correm (RADAR_ENABLED=false). O que está abaixo é o que ficou das recolhas anteriores.</div>
                    </div>
                )}
                {failed && <div className="alert alert-warning" role="alert">Não foi possível carregar o Radar. Tente novamente dentro de momentos.</div>}

                <Row className="g-3 mb-3">
                    {tiles.map((c) => (
                        <Col key={c.id} xs={12} sm={6} xl={3}>
                            <Card className="mb-0 h-100">
                                <CardBody className="d-flex align-items-center gap-3">
                                    <span className="avatar-sm flex-shrink-0">
                                        <span className={`avatar-title bg-${c.color}-subtle text-${c.color} rounded fs-20`}><i className={c.icon} /></span>
                                    </span>
                                    <div className="min-w-0">
                                        <div className="fs-20 fw-semibold" data-testid={`radar-tile-${c.id}`}>{num(c.value)}</div>
                                        <small className="text-muted">{c.label}</small>
                                        {c.sub && <div className="text-muted fs-12">{c.sub}</div>}
                                    </div>
                                </CardBody>
                            </Card>
                        </Col>
                    ))}
                </Row>

                <PageCard
                    title="Itens"
                    info="Cada título que entrou, com o veredicto do filtro de segurança. As ligações abrem a fonte noutro separador."
                    status={pg ? <>{num(pg.total)} {pg.total === 1 ? "item" : "itens"}</> : undefined}
                    loading={loadingItems && (items?.items.length ?? 0) > 0}
                    actions={<>{viewToggle}{itemCols.selector}</>}
                    filters={
                        <RestFilterBar activeCount={(source ? 1 : 0) + (days !== 1 ? 1 : 0)} onClear={() => { setSource(""); setDays(1); }}>
                            <div style={{ flex: "1 1 200px", minWidth: 0 }}>
                                <XSelect<string> small ariaLabel="Fonte" options={sourceOptions} value={source} onChange={(v) => setSource(v)} />
                            </div>
                            <div style={{ flex: "1 1 200px", minWidth: 0 }}>
                                <XSelect<number> small ariaLabel="Período" options={dayOptions} value={days} onChange={(v) => setDays(v)} />
                            </div>
                        </RestFilterBar>
                    }
                >
                    <DataTable
                        columns={itemCols}
                        data={items?.items ?? []}
                        rowKey={(i) => i.id}
                        mode="server"
                        loading={loadingItems}
                        caption="Itens do Radar"
                        empty={{ message: emptyMessage }}
                        server={{
                            page: pg?.current_page ?? page, lastPage: pg?.last_page ?? 1, total: pg?.total ?? 0, perPage: pg?.per_page ?? PER_PAGE,
                            from: pg?.from ?? 0, to: pg?.to ?? 0, onPageChange: setPage,
                        }}
                        data-testid="radar-items"
                    />
                </PageCard>

                <PageCard title="Tendências recentes" info="As tendências juntam itens: uma pesquisa do Google Trends, o mesmo assunto em dois ou mais jornais, uma decisão do BCE. Um item retirado retira a tendência."
                    actions={topicCols.selector} loading={loadingSummary && !!summary}>
                    <DataTable columns={topicCols} data={summary?.topics ?? []} rowKey={(r) => r.id} loading={loadingSummary} caption="Tendências recentes"
                        pageSize={10} empty={{ message: "Ainda não há tendências." }} />
                </PageCard>

                <PageCard title="Relevância, sinais e empresas" data-testid="radar-r2"
                    info="A relevância é avaliada uma vez por tendência e por ramo (Haiku 5.5, em lotes). Cada empresa com o Radar ligado recebe no máximo 3 sinais por dia. O interruptor de cada empresa é o módulo radar."
                    actions={
                        <div className="xp-seg" role="tablist" aria-label="Painel">
                            {([["relevancia", "Relevância"], ["sinais", "Sinais"], ["empresas", "Empresas"], ["ine", "Freguesias e INE"]] as const).map(([k, l]) => (
                                <button key={k} type="button" role="tab" aria-selected={panel === k} className={panel === k ? "on" : ""} onClick={() => setPanel(k)}>{l}</button>
                            ))}
                        </div>
                    }>
                    {panel === "relevancia" && <DataTable columns={sectorCols} data={summary?.relevance ?? []} rowKey={(r) => r.sector} loading={loadingSummary} caption="Relevância por ramo"
                        paginate={false} empty={{ message: "Ainda nenhuma tendência foi avaliada." }} />}
                    {panel === "sinais" && <DataTable columns={signalCols} data={summary?.signals ?? []} rowKey={(r) => r.company_id} loading={loadingSummary} caption="Sinais por empresa"
                        paginate={false} empty={{ message: "Ainda não há sinais." }} />}
                    {panel === "empresas" && <DataTable columns={companyCols} data={summary?.companies ?? []} rowKey={(r) => r.id} loading={loadingSummary} caption="Radar por empresa"
                        pageSize={25} empty={{ message: "Sem empresas." }} />}
                    {panel === "ine" && <IneBlock ine={summary?.ine} />}
                </PageCard>

                <Row className="g-3">
                    <Col xl={8}>
                        <PageCard title="Fontes" info="Só entram fontes cujos termos de uso foram lidos. No máximo 4 pedidos por fonte e por hora."
                            actions={sourceCols.selector}>
                            <DataTable columns={sourceCols} data={summary?.sources ?? []} rowKey={(s) => s.key} loading={loadingSummary} caption="Fontes do Radar"
                                paginate={false} empty={{ message: "Sem fontes configuradas." }} />
                        </PageCard>
                    </Col>
                    <Col xl={4}>
                        <PageCard title="Filtro de segurança" info="Corre antes de qualquer IA e não se desliga. Os motivos retiram o item; as marcas deixam-no passar com um aviso no cartão.">
                            <DataTable columns={filterCols} data={filterRows} rowKey={(r) => r.key} loading={loadingSummary} caption="Filtro de segurança"
                                paginate={false} empty={{ message: "Sem dados do filtro." }} />
                        </PageCard>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}

/** R3: a referência do INE e as freguesias (pelo DICOFRE); as que não casam e as diferenças com a lista oficial. Nada é apagado. */
function IneBlock({ ine }: { ine?: RadarAdminIne }) {
    if (!ine) return <p className="text-muted p-3 mb-0">Sem dados.</p>;
    const p = ine.parishes;
    return (
        <div className="p-3" data-testid="radar-ine">
            <p className="mb-1"><strong>Referência do INE:</strong> {ine.period ? `${ine.period}, ${ine.records.toLocaleString("pt-PT")} registos, ${ine.parishes_with_value.toLocaleString("pt-PT")} freguesias com valor; ${ine.platform_parishes_with_reference.toLocaleString("pt-PT")} freguesias da plataforma com referência.` : "ainda não foi lida."}</p>
            <p className="text-muted fs-12">{ine.attribution}</p>
            <p className="mb-2"><strong>Freguesias:</strong> {p.with_code.toLocaleString("pt-PT")} de {p.platform.toLocaleString("pt-PT")} da plataforma com o código oficial (DICOFRE); a lista oficial tem {p.official.toLocaleString("pt-PT")}.</p>
            <Row className="g-3">
                <Col lg={6}>
                    <h6 className="fs-13">Sem código ({p.without_code.length})</h6>
                    <ul className="list-unstyled fs-13 mb-0" data-testid="radar-ine-without-code">{p.without_code.map((x) => <li key={x.id}>{x.name}<span className="text-muted">{x.municipality ? `, ${x.municipality}` : ""}</span></li>)}</ul>
                </Col>
                <Col lg={6}>
                    <h6 className="fs-13">Na lista oficial e não na plataforma ({p.official_missing_count.toLocaleString("pt-PT")}; uniões e desagregações)</h6>
                    <ul className="list-unstyled fs-13 mb-0" style={{ maxHeight: 320, overflowY: "auto" }}>{p.official_missing.map((x) => <li key={x.dicofre}>{x.name}<span className="text-muted">, {x.municipality} ({x.dicofre})</span></li>)}</ul>
                </Col>
            </Row>
        </div>
    );
}
