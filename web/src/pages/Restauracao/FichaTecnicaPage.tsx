import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { Button, Container, Row, Col } from "reactstrap";
import { ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import Skeleton from "Components/Common/Skeleton";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { useCan } from "contexts/ModulesContext";
import { getRecipe, getRecipeEditor } from "helpers/laravel_helper";
import { RecipeDetail, RecipeLine, RecipeVersion, RecipeWarning, RecipeWriteInfo } from "common/models/recipe.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { COST_SOURCES_TEXT, DiffBadge, Money, StateBadge, fmtDate, fmtPct, fmtQty } from "./fichas/recipeFormat";
import ConfirmFactorModal from "./fichas/ConfirmFactorModal";
import ConfirmYieldModal from "./fichas/ConfirmYieldModal";
import RecipeEditorModal from "./fichas/RecipeEditor";
import RecipeHistory from "./fichas/RecipeHistory";
import DeleteRecipeModal from "./fichas/DeleteRecipeModal";
import WriteProgressModal from "./fichas/WriteProgressModal";
import { WarningLegend, WarningTip } from "./fichas/warningInfo";

/**
 * XPLENDOR — Restauração › Fichas técnicas › artigo (Fatias 1 e 1b, só leitura no PingWin). As
 * versões da ficha (a em vigor marcada) e as lojas de cada uma, as linhas com o custo XPLENDOR
 * (último e médio, e de onde veio a quantidade) e o do PingWin por armazém, os semi-produtos (com a
 * ficha deles e o rendimento), os totais, os avisos e onde o artigo é usado. "Confirmar fator" e
 * "Confirmar" (rendimento) escrevem só na XPLENDOR.
 */

const STATE_LABEL = { com_ficha: "Com ficha", sem_ficha: "Sem ficha", sem_custo: "Com ficha mas sem custo", loja_sem_ficha: "Loja sem ficha em vigor" } as const;
const FLAG_LABEL = { rendimento_por_confirmar: "Rendimento por confirmar", fator_por_confirmar: "Fator por confirmar" } as const;

const COST_STATUS: Record<RecipeLine["cost_status"], { label: string; cls: string } | null> = {
    ok: null,
    antigo: { label: "antigo", cls: "bg-warning-subtle text-warning" },
    sem_custo: { label: "sem custo", cls: "bg-danger-subtle text-danger" },
    parcial: { label: "parcial", cls: "bg-warning-subtle text-warning" },
    ciclo: { label: "ciclo", cls: "bg-danger-subtle text-danger" },
    fundo: { label: "mais de 5 níveis", cls: "bg-danger-subtle text-danger" },
    zero: { label: "custo 0", cls: "bg-info-subtle text-info" },
};

const QTY_SOURCE: Record<string, string> = {
    confirmado: "quantidade pelo fator confirmado",
    raw: "quantidade da compra",
    misto: "quantidade: fator confirmado e da compra",
};

/** A linha precisa do botão "Confirmar fator": tem compras e um aviso de unidade, de fator ou de diferença. */
const factorCandidate = (l: RecipeLine) =>
    !!l.conversion && (l.needs_factor || l.warnings.some((w) => w.type === "unit_factor") || (l.diff_pct !== null && Math.abs(l.diff_pct) > 20));

function WarningIcon({ warnings }: { warnings: RecipeWarning[] }) {
    if (!warnings.length) return null;
    const onlyInfo = warnings.every((w) => w.level === "info");
    return (
        <span className={`${onlyInfo ? "text-info" : "text-warning"} ms-1`} title={warnings.map((w) => w.text).join("\n")} aria-label={`Avisos: ${warnings.map((w) => w.text).join(" ")}`}>
            <i className={onlyInfo ? "ri-information-line" : "ri-alert-line"} aria-hidden />
        </span>
    );
}

/** O custo PingWin de uma linha no armazém escolhido ("" = todos). */
const pingwinOf = (l: RecipeLine, wh: string) =>
    wh === "" ? l.pingwin_cost_eur : (l.pingwin_by_warehouse.find((w) => w.warehouse_id === wh)?.cost_eur ?? null);
const pingwinUnitOf = (l: RecipeLine, wh: string) =>
    wh === "" ? l.pingwin_unit_cost_eur : (l.pingwin_by_warehouse.find((w) => w.warehouse_id === wh)?.unit_cost_eur ?? null);
const diffOf = (l: RecipeLine, wh: string) => {
    if (wh === "") return l.diff_pct;
    const pw = pingwinOf(l, wh);
    return l.line_cost_eur !== null && pw ? Math.round(((l.line_cost_eur - pw) / pw) * 1000) / 10 : null;
};

/** As linhas de uma versão (e, por baixo, as fichas dos semi-produtos abertos). */
function LinesTable({ lines, hidden, tableKey, depth = 0, total, wh, factorAction, head, toolbar }: {
    lines: RecipeLine[]; hidden: boolean; tableKey: string; depth?: number;
    total?: { cost: number | null; complete: boolean };
    wh: string;
    factorAction?: (l: RecipeLine) => React.ReactNode;
    /** A versão mostrada (data, estado, lojas), por cima da barra. */
    head?: React.ReactNode;
    /** Fatia 4 (B2): as versões e o armazém, na mesma linha e com a mesma altura do "Colunas". */
    toolbar?: React.ReactNode;
}) {
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const money = (v: number | null, seed: number, decimals = 4) => <Money value={v} hidden={hidden && v === null} decimals={decimals} seed={seed} />;

    const columns: DTColumn<RecipeLine>[] = [
        { id: "code", header: "Código", value: (l) => l.code, cell: (l) => <span className="fw-medium">{l.code ?? "—"}</span>, mobile: "subtitle", nowrap: true, defaultVisible: false },
        {
            id: "description", header: "Descrição", value: (l) => l.description, mobile: "title", hideable: false,
            cell: (l) => (
                <div style={{ minWidth: 190 }}>
                    <span className={l.inactive ? "text-muted" : undefined}>{l.description}</span>
                    {l.inactive && <span className="badge bg-secondary-subtle text-secondary ms-1">inativo</span>}
                    {l.is_bom && <span className="badge bg-primary-subtle text-primary ms-1">semi-produto</span>}
                    <WarningIcon warnings={l.warnings} />
                    {l.similar && (
                        <div className="fs-12 text-muted" data-testid="similar">
                            <i className="ri-lightbulb-line me-1" aria-hidden />Compra-se um parecido:{" "}
                            <Link to={`/restauracao/artigos/${l.similar.product_id}`} onClick={(e) => e.stopPropagation()}>{l.similar.code} {l.similar.description}</Link>
                            {", último "}<Money value={l.similar.last_eur} hidden={hidden} decimals={4} seed={9} />
                        </div>
                    )}
                    {l.code && <div className="text-muted fs-12">{l.code}</div>}
                    {l.is_bom && l.sub && (
                        <div>
                            <Button size="sm" color="link" className="p-0 fs-12" onClick={(e) => { e.stopPropagation(); setOpen((o) => ({ ...o, [l.key]: !o[l.key] })); }}
                                aria-expanded={!!open[l.key]}>
                                <i className={`${open[l.key] ? "ri-arrow-up-s-line" : "ri-arrow-down-s-line"} me-1`} />{open[l.key] ? "Esconder a ficha" : "Ver a ficha"}
                            </Button>
                        </div>
                    )}
                </div>
            ),
        },
        { id: "qnt", header: "Quantidade", value: (l) => l.qnt, cell: (l) => fmtQty(l.qnt, 4), align: "end", nowrap: true },
        { id: "unit", header: "Unidade", value: (l) => l.unit, cell: (l) => l.unit ?? "—", nowrap: true },
        { id: "qnt_base", header: "Quantidade base", value: (l) => l.qnt_base, cell: (l) => fmtQty(l.qnt_base, 4), align: "end", nowrap: true, defaultVisible: false },
        { id: "waste", header: "Desperdício", value: (l) => l.waste_pct, cell: (l) => fmtPct(l.waste_pct, 1), align: "end", nowrap: true },
        { id: "recovery", header: "Recuperação", value: (l) => l.recovery_pct, cell: (l) => fmtPct(l.recovery_pct, 1), align: "end", nowrap: true, defaultVisible: false },
        {
            id: "unit_cost", header: "Custo unitário", value: (l) => l.unit_cost_avg_eur, align: "end", nowrap: true,
            cell: (l) => {
                const st = COST_STATUS[l.cost_status];
                return (
                    <div>
                        {l.cost_source === "ficha"
                            ? <>{money(l.unit_cost_avg_eur, 1)}<div className="text-muted fs-11">da ficha, por {l.sub?.yield.unit_label.toLowerCase() ?? "unidade"}</div></>
                            : l.unit_cost_avg_eur === null && !hidden ? <span className="text-muted">—</span>
                                : <>{money(l.unit_cost_last_eur, 2)} · {money(l.unit_cost_avg_eur, 3)}</>}
                        {st && <div><span className={`badge ${st.cls} fs-11`}>{st.label}</span></div>}
                        {l.low_confidence && <div><span className="badge bg-warning-subtle text-warning fs-11" title="Rendimento do semi-produto por confirmar">baixa confiança</span></div>}
                        {l.cost_status === "antigo" && l.last_purchase && <div className="text-muted fs-11">compra de {fmtDate(l.last_purchase)}</div>}
                        {l.qty_source && <div className={`fs-11 ${l.qty_source === "confirmado" ? "text-success" : "text-muted"}`}>{QTY_SOURCE[l.qty_source]}</div>}
                    </div>
                );
            },
        },
        {
            id: "pingwin", header: wh === "" ? "Custo PingWin" : "Custo PingWin (armazém)", value: (l) => pingwinOf(l, wh), align: "end", nowrap: true,
            cell: (l) => (
                <div>
                    {money(pingwinOf(l, wh), 4)}
                    <div className="text-muted fs-11">{money(pingwinUnitOf(l, wh), 8)} por {(l.unit ?? "unidade").toLowerCase()}</div>
                </div>
            ),
        },
        { id: "line_cost", header: "Custo da linha", value: (l) => l.line_cost_eur, cell: (l) => <span className="fw-medium">{money(l.line_cost_eur, 5)}</span>, align: "end", nowrap: true },
        { id: "diff", header: "Diferença", value: (l) => diffOf(l, wh), cell: (l) => <DiffBadge pct={diffOf(l, wh)} />, align: "end", nowrap: true },
    ];
    const cols = useDataColumns(`restauracao.ficha-tecnica.linhas${depth ? ".semi" : ""}`, columns);
    const semis = lines.filter((l) => l.is_bom && l.sub && open[l.key]);
    const pwTotal = lines.reduce((acc, l) => acc + (pingwinOf(l, wh) ?? 0), 0);
    const anyFactor = !!factorAction && lines.some(factorCandidate);

    return (
        <>
            {depth === 0 && (
                // A zona de filtros, como nos outros quadros com DataTable (PageCard `filters`): padding e linha por baixo.
                <div className="card-body border-bottom xp-card-filters" data-testid="lines-filters">
                    {head && <div className="d-flex flex-wrap align-items-center gap-2 mb-2">{head}</div>}
                    <div className="d-flex flex-wrap align-items-center gap-2" data-testid="lines-toolbar">
                        {toolbar}
                        <div className="ms-auto">{cols.selector}</div>
                    </div>
                </div>
            )}
            <DataTable
                columns={cols}
                data={lines}
                rowKey={(l) => l.key}
                paginate={false}
                caption="Linhas da ficha técnica"
                rowClassName={(l) => (l.inactive ? "text-muted" : undefined)}
                rowActions={anyFactor ? (l) => (factorCandidate(l) ? factorAction!(l) : null) : undefined}
                empty={{ message: "Esta versão não tem linhas." }}
                footerRow={total ? {
                    label: <span className="fw-semibold">Total{total.complete ? "" : " (parcial)"}</span>,
                    cells: {
                        pingwin: <span className="fw-semibold">{money(hidden ? null : pwTotal, 6, 2)}</span>,
                        line_cost: <span className="fw-semibold">{money(total.cost, 7, 2)}</span>,
                    },
                } : undefined}
                data-testid={`recipe-lines-${tableKey}`}
            />
            {semis.map((l) => {
                const sub = l.sub!;
                const y = sub.yield;
                return (
                    <div key={l.key} className="border-top bg-light-subtle px-3 py-2" data-testid="semi-ficha">
                        <div className="fs-13 fw-semibold mb-1">
                            <i className="ri-git-branch-line me-1" />Ficha do semi-produto {l.description} (versão de {fmtDate(sub.version.valid_from)}):{" "}
                            lote de <Money value={sub.cost_eur} hidden={hidden} decimals={2} seed={20} />, rende {y.confirmed ? fmtQty(y.quantity, 3) : "1"} {y.unit_label}
                            {y.confirmed ? <span className="badge bg-success-subtle text-success ms-1">confirmado</span> : <span className="badge bg-warning-subtle text-warning ms-1">rendimento por confirmar</span>}
                            {" "}· <Money value={sub.cost_per_unit_eur} hidden={hidden} decimals={4} seed={21} /> por {y.unit_label.toLowerCase()}
                            {" "}<Link to={`/restauracao/fichas-tecnicas/${sub.product_id}`} className="fw-normal ms-1">Abrir</Link>
                        </div>
                        <LinesTable lines={sub.lines} hidden={hidden} tableKey={`${tableKey}-${l.key}`} depth={depth + 1} wh={wh}
                            total={{ cost: sub.cost_eur, complete: sub.cost_complete }} factorAction={factorAction} />
                    </div>
                );
            })}
        </>
    );
}

function StoreBadges({ v }: { v: RecipeVersion }) {
    if (!v.stores.length) return <span className="text-muted fs-12">Sem lojas</span>;
    return (
        <div className="d-flex flex-wrap gap-1">
            {v.stores.map((s) => {
                const cls = !s.active ? "bg-secondary-subtle text-secondary text-decoration-line-through"
                    : s.in_force ? "bg-success-subtle text-success" : "bg-info-subtle text-info";
                const title = !s.active ? "Loja inativa nesta versão" : s.in_force ? "Em vigor nesta loja" : "Ativa, mas há uma versão mais recente em vigor";
                return <span key={s.store_id} className={`badge ${cls}`} title={title}>{s.name ?? s.code}</span>;
            })}
        </div>
    );
}

export default function FichaTecnicaPage() {
    const { productId } = useParams<{ productId: string }>();
    const companyId = useWorkingCompanyId();
    const navigate = useNavigate();
    const [canEdit, editReason] = useCan("restauracao.editar");
    const [data, setData] = useState<RecipeDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [tab, setTab] = useState<string | null>(null);
    const [wh, setWh] = useState("");
    const [factorLine, setFactorLine] = useState<RecipeLine | null>(null);
    const [yieldOpen, setYieldOpen] = useState(false);
    // Fatia 4: editor (num modal), apagar e histórico.
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState<{ target: { bom_id: string; valid_from: string | null } | "all"; signature: string } | null>(null);
    const [deleteWrite, setDeleteWrite] = useState<RecipeWriteInfo | null>(null);
    const [historyKey, setHistoryKey] = useState(0);

    const load = useCallback(async () => {
        if (!companyId || !productId) return;
        setLoading(true);
        setError(null);
        try {
            const res: any = await getRecipe(companyId, productId);
            setData(res?.data ?? null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível carregar a ficha técnica.");
        } finally {
            setLoading(false);
        }
    }, [companyId, productId]);
    useEffect(() => { load(); }, [load]);

    const article = data?.article;
    document.title = `${article?.description ?? "Ficha técnica"} | Fichas técnicas | Xplendor`;
    const hidden = data?.financial?.visible === false;
    const versions = useMemo(() => data?.versions ?? [], [data]);
    const current = useMemo(() => versions.find((v) => v.bom_id === tab) ?? versions.find((v) => v.in_force) ?? versions[0] ?? null, [versions, tab]);
    const inForce = versions.find((v) => v.in_force) ?? null;
    const y = inForce?.yield ?? null;
    const noEditReason = canEdit ? null : (editReason ?? "Precisa da permissão de editar os artigos da restauração.");
    const noDeleteReason = noEditReason ?? data?.write_guard?.reason ?? null;
    const afterWrite = useCallback(() => { load(); setHistoryKey((k) => k + 1); }, [load]);
    const askDelete = async (target: { bom_id: string; valid_from: string | null } | "all") => {
        if (!companyId || !productId) return;
        try {
            const res: any = await getRecipeEditor(companyId, productId);
            if (res?.data?.active_write) { setDeleteWrite(res.data.active_write); return; }
            setDeleting({ target, signature: res?.data?.signature ?? "" });
        } catch {
            /* o erro já aparece no toast da API */
        }
    };

    // Auditoria ("Corrigir"): ?editar=1 abre o editor (ou "Criar ficha"), ?fator={componente} abre o
    // "Confirmar fator" dessa linha e ?rendimento=1 o "Confirmar" do rendimento. Uma vez, depois de carregar.
    const [params, setParams] = useSearchParams();
    const handled = useRef(false);
    useEffect(() => {
        if (!data || handled.current) return;
        const editar = params.get("editar");
        const fator = params.get("fator");
        const rendimento = params.get("rendimento");
        if (!editar && !fator && !rendimento) return;
        handled.current = true;
        if (editar && canEdit) setEditing(true);
        if (fator) {
            const line = (inForce ?? versions[0])?.lines.find((l) => l.component_id === fator && l.conversion);
            if (line) setFactorLine(line);
        }
        if (rendimento && inForce?.yield) setYieldOpen(true);
        const next = new URLSearchParams(params);
        ["editar", "fator", "rendimento"].forEach((k) => next.delete(k));
        setParams(next, { replace: true });
    }, [data, params, setParams, canEdit, inForce, versions]);

    const warehouseOptions = useMemo(() => [{ value: "", label: "Todos os armazéns" }, ...(data?.warehouses ?? []).map((w) => ({ value: w.id, label: w.name }))], [data?.warehouses]);

    const factorAction = (l: RecipeLine) => (
        <ReasonButton size="sm" color="outline-primary" reason={noEditReason} onClick={() => setFactorLine(l)}>
            <i className="ri-scales-3-line me-1" aria-hidden />Confirmar fator
        </ReasonButton>
    );

    const kpi = (label: string, node: React.ReactNode, sub?: React.ReactNode) => (
        <Col xs={6} md={4} xl={2}>
            <div className="text-muted fs-12">{label}</div>
            <div className="fs-16 fw-semibold">{node}</div>
            {sub && <div className="text-muted fs-12">{sub}</div>}
        </Col>
    );

    const costBadge = inForce && !inForce.cost_complete && inForce.line_count > 0 && (inForce.cost_eur === null && !hidden
        ? <span className="badge bg-danger-subtle text-danger fs-11 ms-1">sem custo</span>
        : <span className="badge bg-warning-subtle text-warning fs-11 ms-1">parcial</span>);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title={article?.description ?? "Ficha técnica"}
                    crumbLabel="Ficha técnica"
                    breadcrumbs={[{ label: "Restauração" }, { label: "Fichas técnicas", to: "/restauracao/fichas-tecnicas" }]}
                    info="A ficha técnica do artigo no PingWin (só leitura), com o custo calculado pela XPLENDOR a partir das compras."
                />
                {hidden && <FinancialNote className="mb-3" />}
                <WarningLegend />

                {error && !data ? (
                    <PageCard title="Ficha técnica" flush={false}>
                        <p className="text-danger mb-2">{error}</p>
                        <Button size="sm" color="outline-primary" onClick={load}><i className="ri-refresh-line me-1" />Tentar de novo</Button>
                    </PageCard>
                ) : (
                    <>
                        <PageCard
                            title={<>{article?.code ? `${article.code} · ` : ""}{article?.description ?? "Artigo"}</>}
                            status={article?.family ?? undefined}
                            info={COST_SOURCES_TEXT}
                            loading={loading}
                            skeleton={data ? undefined : <div className="p-3"><Skeleton variant="lines" lines={3} label="" /></div>}
                            flush={false}
                            actions={<>
                                {data && <StateBadge state={data.state} label={STATE_LABEL[data.state]} />}
                                {data?.flags.map((f) => <span key={f} className="badge bg-warning-subtle text-warning" data-testid="recipe-flag">{FLAG_LABEL[f]}</span>)}
                                <Button size="sm" color="outline-primary" onClick={() => navigate(`/restauracao/artigos/${productId}`)}>
                                    <i className="ri-external-link-line me-1" />Abrir o artigo
                                </Button>
                            </>}
                        >
                            {data && (
                                <>
                                    {data.missing_stores.length > 0 && (
                                        <div className="alert alert-danger py-2 fs-13" role="alert">
                                            <i className="ri-store-2-line me-1" />Sem versão da ficha em vigor em: <strong>{data.missing_stores.join(", ")}</strong> (vendeu nos últimos 90 dias). <WarningTip type="store_without" />
                                        </div>
                                    )}
                                    <Row className="g-3">
                                        {article?.is_semi && y ? (
                                            <>
                                                {kpi("Custo do lote", <><Money value={inForce?.cost_eur ?? null} hidden={hidden && !!inForce && inForce.line_count > 0} decimals={2} seed={1} />{costBadge}</>,
                                                    inForce ? <>PingWin: <Money value={inForce.pingwin_cost_eur} hidden={hidden && inForce.line_count > 0} decimals={2} seed={2} />{inForce.diff_pct !== null && <> <DiffBadge pct={inForce.diff_pct} /></>}</> : undefined)}
                                                {kpi("Rende", <span data-testid="yield">{y.quantity !== null ? `${fmtQty(y.quantity, 3)} ${y.unit_label}` : "—"}{" "}
                                                    {y.confirmed ? <span className="badge bg-success-subtle text-success fs-11">confirmado</span> : <span className="badge bg-warning-subtle text-warning fs-11">{y.quantity !== null ? "sugerido" : "por confirmar"}</span>}</span>,
                                                    <>
                                                        {y.confirmed ? `Por ${y.confirmed_by ?? "alguém"}${y.confirmed_at ? ` em ${fmtDate(y.confirmed_at)}` : ""}` : (y.reason ?? "Sem sugestão")}
                                                        <div className="mt-1">
                                                            <ReasonButton size="sm" color="outline-primary" reason={noEditReason} onClick={() => setYieldOpen(true)}>
                                                                <i className="ri-check-double-line me-1" aria-hidden />{y.confirmed ? "Alterar" : "Confirmar"}
                                                            </ReasonButton>
                                                        </div>
                                                    </>)}
                                                {kpi(`Custo por ${y.unit_label.toLowerCase()}`, <Money value={inForce?.cost_per_unit_eur ?? null} hidden={hidden && !!inForce && inForce.line_count > 0} decimals={4} seed={3} />,
                                                    y.confirmed ? "Custo do lote ÷ rendimento" : `Sem rendimento confirmado, conta 1 ${y.unit_label}`)}
                                            </>
                                        ) : (
                                            <>
                                                {kpi("Custo XPLENDOR", <><Money value={inForce?.cost_eur ?? null} hidden={hidden && !!inForce && inForce.line_count > 0} decimals={2} seed={1} />{costBadge}
                                                    {inForce?.low_confidence && <span className="badge bg-warning-subtle text-warning fs-11 ms-1">baixa confiança</span>}</>,
                                                    inForce ? <>PingWin: <Money value={inForce.pingwin_cost_eur} hidden={hidden && inForce.line_count > 0} decimals={2} seed={2} />{inForce.diff_pct !== null && <> <DiffBadge pct={inForce.diff_pct} /></>}</> : "Sem versão em vigor")}
                                                {kpi("Preço sem IVA (90 dias)", <Money value={article?.price_eur ?? null} hidden={hidden && (article?.qty_90d ?? 0) > 0} seed={3} />,
                                                    <>Preço de tabela: <Money value={article?.list_price_eur ?? null} hidden={hidden} seed={4} /> com IVA</>)}
                                                {kpi("Food cost", inForce?.food_cost_pct !== null && inForce?.food_cost_pct !== undefined ? fmtPct(inForce.food_cost_pct) : "—")}
                                                {kpi("Margem", <Money value={inForce?.margin_eur ?? null} hidden={hidden && !!inForce && inForce.line_count > 0 && (article?.qty_90d ?? 0) > 0} seed={5} />)}
                                            </>
                                        )}
                                        {kpi("Quantidade vendida", fmtQty(article?.qty_90d ?? 0, 0), `De ${fmtDate(data.window.since)} a ${fmtDate(data.window.until)}`)}
                                        {kpi("Versão em vigor", inForce ? fmtDate(inForce.valid_from) : "—", inForce ? `${inForce.line_count} ${inForce.line_count === 1 ? "componente" : "componentes"}` : undefined)}
                                    </Row>
                                </>
                            )}
                        </PageCard>

                        {data && versions.length === 0 && (
                            <PageCard title="Ficha técnica" flush={false}
                                actions={<ReasonButton type="button" size="sm" color="primary" reason={noEditReason} onClick={() => setEditing(true)}><i className="ri-add-line me-1" aria-hidden />Criar ficha</ReasonButton>}>
                                <p className="text-muted mb-0">Este artigo não tem ficha técnica no PingWin (ou ainda não foi lida: a leitura corre de noite).</p>
                            </PageCard>
                        )}

                        {current && (
                            <PageCard
                                title="Versões da ficha"
                                status={<>Em vigor: a de data mais recente até hoje, não anulada, com a loja ativa</>}
                                actions={<>
                                    <ReasonButton type="button" size="sm" color="outline-primary" reason={noEditReason} onClick={() => setEditing(true)} data-testid="ficha-editar">
                                        <i className="ri-edit-line me-1" aria-hidden />Editar ficha
                                    </ReasonButton>
                                    {/* Apagar fica aqui, fora do editor; com a guarda (dev), desativado com o porquê. */}
                                    <ActionsMenu size="sm" label="Mais ações: ficha técnica" items={[
                                        { label: `Apagar a versão de ${fmtDate(current.valid_from)}`, icon: "ri-delete-bin-line", danger: true, onClick: () => askDelete({ bom_id: current.bom_id, valid_from: current.valid_from }), disabledReason: noDeleteReason },
                                        { label: "Apagar a ficha inteira", icon: "ri-delete-bin-2-line", danger: true, onClick: () => askDelete("all"), disabledReason: noDeleteReason },
                                    ]} />
                                </>}
                            >
                                <LinesTable
                                    key={current.bom_id}
                                    lines={current.lines}
                                    hidden={hidden}
                                    tableKey={current.bom_id}
                                    wh={wh}
                                    total={{ cost: current.cost_eur, complete: current.cost_complete }}
                                    factorAction={factorAction}
                                    head={<>
                                        <span className="fw-semibold">Versão de {fmtDate(current.valid_from)}</span>
                                        {current.in_force
                                            ? <span className="badge bg-success-subtle text-success">em vigor</span>
                                            : current.deleted ? <span className="badge bg-secondary-subtle text-secondary">anulada</span>
                                                : <span className="badge bg-light text-body">não está em vigor</span>}
                                        <span className="text-muted fs-12 ms-2">Lojas:</span>
                                        <StoreBadges v={current} />
                                    </>}
                                    toolbar={<>
                                        {versions.length > 1 && (
                                            <div className="xp-seg flex-wrap" role="tablist" aria-label="Versões">
                                                {versions.map((v) => (
                                                    <button key={v.bom_id} type="button" role="tab" aria-selected={current.bom_id === v.bom_id}
                                                        className={current.bom_id === v.bom_id ? "on" : ""} onClick={() => setTab(v.bom_id)}>
                                                        {fmtDate(v.valid_from)}{versions.filter((x) => x.valid_from === v.valid_from).length > 1 && ` (versão ${Number(v.key) + 1})`}{v.in_force && <i className="ri-check-line ms-1" aria-label="em vigor" />}{v.deleted && " (anulada)"}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                        {(data?.warehouses.length ?? 0) > 0 && (
                                            <div style={{ minWidth: 190 }}>
                                                <XSelect ariaLabel="Armazém do custo PingWin" small options={warehouseOptions} value={wh} onChange={setWh} searchable={false} />
                                            </div>
                                        )}
                                    </>}
                                />
                                {current.warnings.length > 0 && (
                                    <div className="border-top px-3 py-3" data-testid="recipe-warnings">
                                        <div className="fw-semibold fs-13 mb-2"><i className="ri-alert-line text-warning me-1" />Avisos ({current.warnings.length})</div>
                                        <ul className="mb-0 fs-13 ps-3">
                                            {current.warnings.map((w, i) => (
                                                <li key={i} className={w.level === "info" ? "text-muted" : undefined}>{w.line ? <><strong>{w.line}</strong>: </> : null}{w.text} <WarningTip type={w.type} /></li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </PageCard>
                        )}

                        {data && (
                            <PageCard title="Usado em" info="As fichas técnicas (versões não anuladas) que usam este artigo como componente." flush={false}>
                                {data.used_in.length === 0 ? (
                                    <p className="text-muted mb-0 fs-13">Não é usado em nenhuma ficha técnica.</p>
                                ) : (
                                    <ul className="list-unstyled mb-0 fs-13">
                                        {data.used_in.map((u, i) => (
                                            <li key={i} className="mb-1">
                                                <Link to={`/restauracao/fichas-tecnicas/${u.product_id}`}>{u.code ? `${u.code} · ` : ""}{u.description}</Link>
                                                <span className="text-muted ms-2">{fmtQty(u.qnt, 4)} {u.unit ?? ""}, versão de {fmtDate(u.valid_from)}</span>
                                                {u.in_force && <span className="badge bg-success-subtle text-success ms-2">em vigor</span>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </PageCard>
                        )}
                    </>
                )}

                {data && productId && <RecipeHistory companyId={companyId} productId={productId} refreshKey={historyKey} />}

                {editing && productId && (
                    <RecipeEditorModal companyId={companyId} productId={productId} bomId={current?.bom_id ?? null} hidden={hidden}
                        onSaved={afterWrite} onClose={() => setEditing(false)} />
                )}

                {deleting && companyId && productId && (
                    <DeleteRecipeModal companyId={companyId} productId={productId} productName={article?.description ?? "artigo"} signature={deleting.signature}
                        target={deleting.target} onClose={() => setDeleting(null)} onQueued={(w) => { setDeleting(null); setDeleteWrite(w); }} />
                )}
                {deleteWrite && companyId && (
                    <WriteProgressModal companyId={companyId} write={deleteWrite} onClose={() => setDeleteWrite(null)} onFinished={afterWrite} />
                )}

                {factorLine?.conversion && (
                    <ConfirmFactorModal open={!!factorLine} line={factorLine} companyId={companyId} hidden={hidden}
                        onClose={() => setFactorLine(null)} onDone={() => { setFactorLine(null); load(); }} />
                )}
                {inForce?.yield && productId && (
                    <ConfirmYieldModal open={yieldOpen} version={inForce} productId={productId} companyId={companyId} hidden={hidden}
                        onClose={() => setYieldOpen(false)} onDone={() => { setYieldOpen(false); load(); }} />
                )}
            </Container>
        </div>
    );
}
