import { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Button, Container, DropdownItem, DropdownMenu, DropdownToggle, Label, UncontrolledDropdown } from "reactstrap";
import { ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import XSelect from "Components/Common/Select";
import RestFilterBar from "Components/Common/RestFilterBar";
import Skeleton from "Components/Common/Skeleton";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { getAuditFindings, getAuditMeta, startAuditRun } from "helpers/laravel_helper";
import { AuditArea, AuditFinding, AuditListResponse, AuditMeta, AuditRun } from "common/models/audit.model";
import { toast } from "react-toastify";
import { AuditStateBadge, Cents, ENTITY_LABEL, SEVERITY, SeverityBadge, fmtWhen } from "./auditFormat";
import AuditFindingPanel from "./AuditFindingPanel";
import AuditRunPanel from "./AuditRunPanel";
import AuditGroupsView from "./AuditGroupsView";

/**
 * XPLENDOR — Auditoria (plataforma): os achados de todas as áreas que a pessoa pode ver
 * (restauração, automóvel, tracking dos sites...), ordenados pelo que está em jogo (o dinheiro, depois
 * o a confirmar, depois a relevância e a urgência), com a % acumulada. Filtros, colunas e ordenação no servidor; o detalhe abre
 * num painel lateral. Os euros só aparecem a quem vê as Finanças.
 */
const PER_PAGE = 25;
type Sort = { id: string; key: string; desc: boolean } | null;
const STATE_OPTIONS = [
    { value: "abertos", label: "Por resolver" }, { value: "aberto", label: "Abertos" }, { value: "aceite", label: "Aceites" },
    { value: "corrigido", label: "Marcados como corrigidos" }, { value: "ignorado", label: "Ignorados" },
    { value: "resolvido", label: "Resolvidos" }, { value: "todos", label: "Todos" },
];
const SEVERITY_OPTIONS = [{ value: "", label: "Todas as urgências" }, ...(["alta", "media", "baixa", "info"] as const).map((s) => ({ value: s, label: SEVERITY[s].label }))];

export default function AuditoriaPage() {
    document.title = "Auditoria | Xplendor";
    const companyId = useWorkingCompanyId();
    const [params, setParams] = useSearchParams();
    const [meta, setMeta] = useState<AuditMeta | null>(null);
    const [data, setData] = useState<AuditListResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [page, setPage] = useState(1);
    const [sort, setSort] = useState<Sort>(null);
    const [search, setSearch] = useState(params.get("search") ?? "");
    const [openId, setOpenId] = useState<number | null>(null);
    const [run, setRun] = useState<AuditRun | null>(null);
    const [reload, setReload] = useState(0);

    const area = params.get("area") ?? "";
    const rule = params.get("rule") ?? "";
    const severity = params.get("severity") ?? "";
    const state = params.get("state") ?? "abertos";
    const entityType = params.get("entity_type") ?? "";
    const entityId = params.get("entity_id") ?? "";
    const entityLabel = params.get("entity_label") ?? "";
    const view: "lista" | "regra" = params.get("vista") === "regra" ? "regra" : "lista";
    const setParam = (key: string, value: string) => {
        const next = new URLSearchParams(params);
        if (value) next.set(key, value); else next.delete(key);
        if (key === "area") next.delete("rule");
        setParams(next, { replace: true });
    };

    useEffect(() => {
        if (!companyId) return;
        getAuditMeta(companyId).then((res: any) => setMeta(res?.data ?? null)).catch(() => setMeta({ areas: [], last_runs: [], financial: { visible: true, note: null } }));
    }, [companyId, reload]);

    const fetchRows = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        setError(null);
        try {
            const res: any = await getAuditFindings(companyId, {
                page, perPage: PER_PAGE, area: area || undefined, rule: rule || undefined, severity: severity || undefined,
                state, entity_type: entityType || undefined, entity_id: entityId || undefined, search: search.trim() || undefined,
                sort: sort?.key, dir: sort ? (sort.desc ? "desc" : "asc") : undefined,
            });
            setData(res?.data ?? null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível carregar os achados.");
        } finally {
            setLoading(false);
        }
    }, [companyId, page, area, rule, severity, state, entityType, entityId, search, sort]);
    useEffect(() => { const t = setTimeout(fetchRows, 250); return () => clearTimeout(t); }, [fetchRows, reload]);
    useEffect(() => { setPage(1); }, [area, rule, severity, state, entityType, entityId, search, sort]);

    const hidden = data?.financial?.visible === false || meta?.financial?.visible === false;
    const rows = data?.findings?.data ?? [];
    const pg = data?.findings;
    const summary = data?.summary;
    const areas = useMemo(() => meta?.areas ?? [], [meta]);
    const rules = useMemo(() => areas.filter((a) => !area || a.key === area).flatMap((a) => a.rules), [areas, area]);
    const ruleValue = rule.includes(",") ? "" : rule;

    const startRun = async (a: AuditArea) => {
        if (!companyId) return;
        try {
            const res: any = await startAuditRun(companyId, a, "central");
            setRun(res?.data?.run ?? null);
        } catch (e: any) {
            if (e?.__status === 409 && e?.errors?.run) setRun(e.errors.run);
            else toast.error(e?.message ?? "Não foi possível começar a auditoria. Tente de novo dentro de momentos.");
        }
    };

    const euroSort = (key: string) => (hidden ? undefined : key);
    const columns: DTColumn<AuditFinding>[] = [
        { id: "severity", header: "Urgência", sortKey: "severity", value: (f) => f.severity, cell: (f) => <SeverityBadge severity={f.severity} />, nowrap: true, mobile: "subtitle" },
        {
            id: "title", header: "Achado", sortKey: "title", value: (f) => f.title, hideable: false, mobile: "title",
            cell: (f) => (
                <div style={{ minWidth: 240 }}>
                    <div className="fw-medium">{f.title}</div>
                    <div className="text-muted fs-12">{f.rule_title}{f.note ? <span className="badge bg-warning-subtle text-warning ms-1">{f.note}</span> : null}</div>
                </div>
            ),
        },
        {
            id: "entity", header: "Entidade", sortKey: "entity", value: (f) => f.entity_label,
            cell: (f) => <div style={{ minWidth: 160 }}><div className="fs-13">{f.entity_label}</div><div className="text-muted fs-11">{ENTITY_LABEL[f.entity_type] ?? f.entity_type}</div></div>,
        },
        { id: "area", header: "Área", sortKey: "area", value: (f) => f.area_label, cell: (f) => <span className="badge bg-light text-body">{f.area_label}</span>, nowrap: true },
        {
            // O dinheiro em jogo; senão o a confirmar (não conta para os 80%); senão a relevância.
            id: "impact", header: "Em jogo", sortKey: euroSort("impact"), value: (f) => f.impact_cents ?? f.impact_pending_cents ?? f.weight_cents, align: "end", nowrap: true,
            cell: (f) => (
                <div>
                    {f.impact_cents !== null ? <Cents value={f.impact_cents} hidden={hidden} seed={f.id} />
                        : f.impact_pending_cents !== null || (hidden && f.rule.endsWith("unidade_trocada")) ? <span className="text-muted fs-12">a confirmar <Cents value={f.impact_pending_cents} hidden={hidden} seed={f.id} /></span>
                            : f.weight_cents !== null || hidden ? <span className="text-muted fs-12">relevância <Cents value={f.weight_cents} hidden={hidden} seed={f.id + 1} /></span>
                                : <span className="text-muted">—</span>}
                </div>
            ),
        },
        { id: "pareto", header: "% acumulada", sortable: false, value: (f) => f.pareto_pct, align: "end", nowrap: true, cell: (f) => (f.pareto_pct === null ? <span className="text-muted">—</span> : `${f.pareto_pct.toLocaleString("pt-PT")}%`) },
        { id: "state", header: "Estado", sortKey: "state", value: (f) => f.state, cell: (f) => <AuditStateBadge state={f.state} />, nowrap: true },
        { id: "last_seen", header: "Visto a", sortKey: "last_seen", value: (f) => f.last_seen_at, cell: (f) => <span className="fs-13">{fmtWhen(f.last_seen_at)}</span>, nowrap: true, defaultVisible: false },
        { id: "first_seen", header: "Encontrado a", sortKey: "first_seen", value: (f) => f.first_seen_at, cell: (f) => <span className="fs-13">{fmtWhen(f.first_seen_at)}</span>, nowrap: true, defaultVisible: false },
    ];
    const cols = useDataColumns("auditoria.achados", columns);

    const filterField = (label: string, node: React.ReactNode, basis = "200px") => (
        <div style={{ flex: `1 1 ${basis}`, minWidth: 0 }}>
            <Label className="text-muted fw-semibold fs-11 text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>{label}</Label>
            {node}
        </div>
    );
    const activeFilterCount = [!!area, !!rule, !!severity, state !== "abertos"].filter(Boolean).length;
    const clearFilters = () => { setSearch(""); setParams(new URLSearchParams(), { replace: true }); };
    const lastRun = meta?.last_runs?.slice().sort((a, b) => (b.finished_at ?? "").localeCompare(a.finished_at ?? ""))[0];

    if (meta && areas.length === 0) {
        return (
            <div className="page-content"><Container fluid>
                <PageHeader title="Auditoria" />
                <PageCard title="Auditoria" flush={false}><p className="text-muted mb-0">Não tem acesso a nenhuma área com regras de auditoria nesta empresa.</p></PageCard>
            </Container></div>
        );
    }

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Auditoria"
                    info="A auditoria procura o que está mal nos dados (IVA, preços de compra, unidades, fichas técnicas, faturas, viaturas, eventos do site). Para cada achado diz o que viu, porque importa, o que fazer e quanto está em jogo. Nunca corrige nada sozinha: o botão Corrigir abre o sítio certo." />
                {hidden && <FinancialNote className="mb-3" />}
                <PageCard
                    title="Achados"
                    info="Primeiro o dinheiro em jogo, do maior para o menor; depois o que está a confirmar; no fim, o que só tem relevância (vendas ou compras ligadas). Em caso de empate, o mais urgente primeiro."
                    status={<>
                        {summary ? <>
                            {summary.total} {summary.total === 1 ? "achado" : "achados"}, {summary.with_impact} com dinheiro em jogo
                            {summary.pareto_n80 ? <> · <span data-testid="audit-pareto">Os primeiros {summary.pareto_n80} {summary.pareto_n80 === 1 ? "achado representa" : "achados representam"} 80% do dinheiro em jogo</span></> : null}
                        </> : "A carregar…"}
                        {lastRun?.finished_at && <> · última auditoria a {fmtWhen(lastRun.finished_at)}</>}
                    </>}
                    loading={loading && !!data}
                    skeleton={data ? undefined : <div className="p-3"><Skeleton variant="table" /></div>}
                    actions={<>
                        <div className="xp-seg" role="tablist" aria-label="Vista" data-testid="audit-view">
                            <button type="button" role="tab" aria-selected={view === "lista"} className={view === "lista" ? "on" : ""} onClick={() => setParam("vista", "")}>
                                <i className="ri-list-check me-1" aria-hidden />Lista
                            </button>
                            <button type="button" role="tab" aria-selected={view === "regra"} className={view === "regra" ? "on" : ""} onClick={() => setParam("vista", "regra")}>
                                <i className="ri-stack-line me-1" aria-hidden />Agrupar por regra
                            </button>
                        </div>
                        {view === "lista" && cols.selector}
                        {areas.length === 1 ? (
                            <Button size="sm" color="primary" onClick={() => startRun(areas[0].key)} data-testid="audit-run-central"><i className="ri-shield-check-line me-1" aria-hidden />Correr auditoria</Button>
                        ) : areas.length > 1 ? (
                            <UncontrolledDropdown className="d-inline-block">
                                <DropdownToggle tag="button" type="button" className="btn btn-primary btn-sm" caret data-testid="audit-run-central">
                                    <i className="ri-shield-check-line me-1" aria-hidden />Correr auditoria
                                </DropdownToggle>
                                <DropdownMenu end>
                                    {areas.map((a) => <DropdownItem key={a.key} onClick={() => startRun(a.key)}>{a.label}</DropdownItem>)}
                                </DropdownMenu>
                            </UncontrolledDropdown>
                        ) : null}
                    </>}
                    filters={
                        <>
                            <RestFilterBar search={search} onSearchChange={setSearch} searchPlaceholder="Pesquisar (achado ou entidade)…" activeCount={activeFilterCount} onClear={clearFilters}>
                                {areas.length > 1 && filterField("Área", <XSelect ariaLabel="Área" small searchable={false} value={area}
                                    options={[{ value: "", label: "Todas as áreas" }, ...areas.map((a) => ({ value: a.key, label: a.label }))]} onChange={(v) => setParam("area", v)} />, "160px")}
                                {filterField("Regra", <XSelect ariaLabel="Regra" small value={ruleValue}
                                    options={[{ value: "", label: rule.includes(",") ? "As regras desta auditoria" : "Todas as regras" }, ...rules.map((r) => ({ value: r.code, label: r.title }))]}
                                    onChange={(v) => setParam("rule", v)} />, "240px")}
                                {filterField("Urgência", <XSelect ariaLabel="Urgência" small searchable={false} value={severity} options={SEVERITY_OPTIONS} onChange={(v) => setParam("severity", v)} />, "160px")}
                                {filterField("Estado", <XSelect ariaLabel="Estado" small searchable={false} value={state} options={STATE_OPTIONS} onChange={(v) => setParam("state", v === "abertos" ? "" : v)} />, "180px")}
                            </RestFilterBar>
                            {entityType && entityId && (
                                <div className="mt-2 fs-13" data-testid="audit-entity-filter">
                                    <span className="badge bg-primary-subtle text-primary">
                                        {ENTITY_LABEL[entityType] ?? entityType}: {entityLabel || entityId}
                                        <button type="button" className="btn btn-link btn-sm p-0 ms-1 text-primary" aria-label="Tirar o filtro da entidade"
                                            onClick={() => { const n = new URLSearchParams(params); n.delete("entity_type"); n.delete("entity_id"); n.delete("entity_label"); setParams(n, { replace: true }); }}>
                                            <i className="ri-close-line" aria-hidden />
                                        </button>
                                    </span>
                                </div>
                            )}
                        </>
                    }
                >
                    {view === "regra" && companyId ? (
                        <AuditGroupsView companyId={companyId} hidden={hidden} reloadKey={reload}
                            filters={{ area: area || undefined, rule: rule || undefined, severity: severity || undefined, state, entity_type: entityType || undefined, entity_id: entityId || undefined, search: search.trim() || undefined }}
                            onOpenFinding={(id) => setOpenId(id)}
                            onShowRule={(code) => { const n = new URLSearchParams(params); n.set("rule", code); n.delete("vista"); setParams(n, { replace: true }); }} />
                    ) : error && !data ? (
                        <div className="p-4 text-center">
                            <p className="text-danger mb-2">{error}</p>
                            <Button size="sm" color="outline-primary" onClick={fetchRows}><i className="ri-refresh-line me-1" />Tentar de novo</Button>
                        </div>
                    ) : (
                        <DataTable
                            columns={cols}
                            data={rows}
                            rowKey={(f) => f.id}
                            mode="server"
                            loading={loading}
                            onRowClick={(f) => setOpenId(f.id)}
                            rowClassName={(f) => (f.state === "resolvido" || f.state === "ignorado" ? "text-muted" : undefined)}
                            empty={{ message: state === "abertos" && !rule && !severity && !entityId ? "Nada por resolver. Corra a auditoria para procurar de novo." : "Nenhum achado com estes filtros." }}
                            server={pg ? {
                                page: pg.current_page, lastPage: pg.last_page, total: pg.total, perPage: pg.per_page, from: pg.from ?? 0, to: pg.to ?? 0,
                                onPageChange: setPage, sort: sort ? { id: sort.id, desc: sort.desc } : null, onSortChange: (next) => setSort(next),
                            } : undefined}
                            caption="Achados da auditoria"
                            data-testid="audit-table"
                        />
                    )}
                </PageCard>
            </Container>
            {openId !== null && companyId && <AuditFindingPanel companyId={companyId} findingId={openId} onClose={() => setOpenId(null)} onChanged={() => setReload((k) => k + 1)} />}
            {run && companyId && <AuditRunPanel companyId={companyId} run={run} onClose={() => setRun(null)} onFinished={() => setReload((k) => k + 1)} />}
        </div>
    );
}
