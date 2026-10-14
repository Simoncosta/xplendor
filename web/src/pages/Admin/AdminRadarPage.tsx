import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge, Card, CardBody, Col, Container, Row } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import RestFilterBar from "Components/Common/RestFilterBar";
import XSelect from "Components/Common/Select";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { getAdminRadar, getAdminRadarItems } from "helpers/laravel_helper";
import type { RadarItem, RadarItemsPage, RadarSourceSummary, RadarSummary, RadarTopicKind, RadarTopicSummary, RadarVerdict } from "common/models/radar.model";

/**
 * Administração › Radar (só o root): tudo o que entrou dos feeds públicos (Google Trends, jornais,
 * BCE), o que o filtro de segurança tirou e porquê, as tendências recentes e a última recolha de
 * cada fonte, com a atribuição e os termos. De cada item só se mostra o título, a ligação, a
 * fonte, a hora e o volume aproximado.
 */

type View = "entradas" | "retiradas";
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
