import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Button, Container, Row, Col } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import Skeleton from "Components/Common/Skeleton";
import { FinancialNote } from "Components/Common/HiddenMoney";
import { getRecipe } from "helpers/laravel_helper";
import { RecipeDetail, RecipeLine, RecipeVersion, RecipeWarning } from "common/models/recipe.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { COST_SOURCES_TEXT, DiffBadge, Money, StateBadge, fmtDate, fmtPct, fmtQty } from "./fichas/recipeFormat";

/**
 * XPLENDOR — Restauração › Fichas técnicas › artigo (Fatia 1, só leitura). As versões da ficha (a em
 * vigor marcada) e as lojas de cada uma, as linhas com o custo XPLENDOR (último e médio) e o da
 * PingWin, os semi-produtos (com a ficha deles), os totais, os avisos e onde o artigo é usado.
 * Nada se grava na PingWin.
 */

const COST_STATUS: Record<RecipeLine["cost_status"], { label: string; cls: string } | null> = {
    ok: null,
    antigo: { label: "antigo", cls: "bg-warning-subtle text-warning" },
    sem_custo: { label: "sem custo", cls: "bg-danger-subtle text-danger" },
    parcial: { label: "parcial", cls: "bg-warning-subtle text-warning" },
    ciclo: { label: "ciclo", cls: "bg-danger-subtle text-danger" },
    fundo: { label: "mais de 5 níveis", cls: "bg-danger-subtle text-danger" },
};

function WarningIcon({ warnings }: { warnings: RecipeWarning[] }) {
    if (!warnings.length) return null;
    return (
        <span className="text-warning ms-1" title={warnings.map((w) => w.text).join("\n")} aria-label={`Avisos: ${warnings.map((w) => w.text).join(" ")}`}>
            <i className="ri-alert-line" aria-hidden />
        </span>
    );
}

/** As linhas de uma versão (e, por baixo, as fichas dos semi-produtos abertos). */
function LinesTable({ lines, hidden, tableKey, depth = 0, total }: {
    lines: RecipeLine[]; hidden: boolean; tableKey: string; depth?: number;
    total?: { cost: number | null; complete: boolean; pingwin: number | null };
}) {
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const money = (v: number | null, seed: number, decimals = 4) => <Money value={v} hidden={hidden && v === null} decimals={decimals} seed={seed} />;

    const columns: DTColumn<RecipeLine>[] = [
        { id: "code", header: "Código", value: (l) => l.code, cell: (l) => <span className="fw-medium">{l.code ?? "—"}</span>, mobile: "subtitle", nowrap: true },
        {
            id: "description", header: "Descrição", value: (l) => l.description, mobile: "title", hideable: false,
            cell: (l) => (
                <div style={{ minWidth: 200 }}>
                    <span className={l.inactive ? "text-muted" : undefined}>{l.description}</span>
                    {l.inactive && <span className="badge bg-secondary-subtle text-secondary ms-1">inativo</span>}
                    {l.is_bom && <span className="badge bg-primary-subtle text-primary ms-1">semi-produto</span>}
                    <WarningIcon warnings={l.warnings} />
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
            id: "unit_cost", header: "Custo unitário (último · médio)", value: (l) => l.unit_cost_avg_eur, align: "end", nowrap: true,
            cell: (l) => {
                const st = COST_STATUS[l.cost_status];
                return (
                    <div>
                        {l.cost_source === "ficha"
                            ? <>{money(l.unit_cost_avg_eur, 1)}<div className="text-muted fs-11">da ficha</div></>
                            : l.unit_cost_avg_eur === null && !hidden ? <span className="text-muted">—</span>
                                : <>{money(l.unit_cost_last_eur, 2)} · {money(l.unit_cost_avg_eur, 3)}</>}
                        {st && <div><span className={`badge ${st.cls} fs-11`}>{st.label}</span></div>}
                        {l.cost_status === "antigo" && l.last_purchase && <div className="text-muted fs-11">compra de {fmtDate(l.last_purchase)}</div>}
                    </div>
                );
            },
        },
        { id: "pingwin", header: "Custo PingWin", value: (l) => l.pingwin_cost_eur, cell: (l) => money(l.pingwin_cost_eur, 4), align: "end", nowrap: true },
        { id: "line_cost", header: "Custo da linha", value: (l) => l.line_cost_eur, cell: (l) => <span className="fw-medium">{money(l.line_cost_eur, 5)}</span>, align: "end", nowrap: true },
        { id: "diff", header: "Diferença", value: (l) => l.diff_pct, cell: (l) => <DiffBadge pct={l.diff_pct} />, align: "end", nowrap: true },
    ];
    const cols = useDataColumns(`restauracao.ficha-tecnica.linhas${depth ? ".semi" : ""}`, columns);
    const semis = lines.filter((l) => l.is_bom && l.sub && open[l.key]);

    return (
        <>
            {depth === 0 && <div className="d-flex justify-content-end px-3 pt-2">{cols.selector}</div>}
            <DataTable
                columns={cols}
                data={lines}
                rowKey={(l) => l.key}
                paginate={false}
                caption="Linhas da ficha técnica"
                rowClassName={(l) => (l.inactive ? "text-muted" : undefined)}
                empty={{ message: "Esta versão não tem linhas." }}
                footerRow={total ? {
                    label: <span className="fw-semibold">Total{total.complete ? "" : " (parcial)"}</span>,
                    cells: {
                        pingwin: <span className="fw-semibold">{money(total.pingwin, 6, 2)}</span>,
                        line_cost: <span className="fw-semibold">{money(total.cost, 7, 2)}</span>,
                    },
                } : undefined}
                data-testid={`recipe-lines-${tableKey}`}
            />
            {semis.map((l) => (
                <div key={l.key} className="border-top bg-light-subtle px-3 py-2" data-testid="semi-ficha">
                    <div className="fs-13 fw-semibold mb-1">
                        <i className="ri-git-branch-line me-1" />Ficha do semi-produto {l.description} (versão de {fmtDate(l.sub!.version.valid_from)}), por unidade base
                    </div>
                    <LinesTable lines={l.sub!.lines} hidden={hidden} tableKey={`${tableKey}-${l.key}`} depth={depth + 1}
                        total={{ cost: l.sub!.cost_eur, complete: l.sub!.cost_complete, pingwin: l.sub!.pingwin_cost_eur }} />
                </div>
            ))}
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
    const [data, setData] = useState<RecipeDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [tab, setTab] = useState<string | null>(null);

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

    const kpi = (label: string, node: React.ReactNode, sub?: React.ReactNode) => (
        <Col xs={6} md={4} xl={2}>
            <div className="text-muted fs-12">{label}</div>
            <div className="fs-16 fw-semibold">{node}</div>
            {sub && <div className="text-muted fs-12">{sub}</div>}
        </Col>
    );

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title={article?.description ?? "Ficha técnica"}
                    crumbLabel="Ficha técnica"
                    breadcrumbs={[{ label: "Restauração" }, { label: "Fichas técnicas", to: "/restauracao/fichas-tecnicas" }]}
                    info="A ficha técnica do artigo na PingWin (só leitura), com o custo calculado pela XPLENDOR a partir das compras."
                />
                {hidden && <FinancialNote className="mb-3" />}

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
                                {data && <StateBadge state={data.state} label={{ com_ficha: "Com ficha", sem_ficha: "Sem ficha", sem_custo: "Com ficha mas sem custo", loja_sem_ficha: "Loja sem ficha em vigor" }[data.state]} />}
                                <Button size="sm" color="outline-primary" onClick={() => navigate(`/restauracao/artigos/${productId}`)}>
                                    <i className="ri-external-link-line me-1" />Abrir o artigo
                                </Button>
                            </>}
                        >
                            {data && (
                                <>
                                    {data.missing_stores.length > 0 && (
                                        <div className="alert alert-danger py-2 fs-13" role="alert">
                                            <i className="ri-store-2-line me-1" />Sem versão da ficha em vigor em: <strong>{data.missing_stores.join(", ")}</strong> (vendeu nos últimos 90 dias).
                                        </div>
                                    )}
                                    <Row className="g-3">
                                        {kpi("Custo XPLENDOR", <><Money value={inForce?.cost_eur ?? null} hidden={hidden && !!inForce && inForce.line_count > 0} decimals={2} seed={1} />{inForce && !inForce.cost_complete && inForce.line_count > 0 && (inForce.cost_eur === null && !hidden
                                            ? <span className="badge bg-danger-subtle text-danger fs-11 ms-1">sem custo</span>
                                            : <span className="badge bg-warning-subtle text-warning fs-11 ms-1">parcial</span>)}</>,
                                            inForce ? <>PingWin: <Money value={inForce.pingwin_cost_eur} hidden={hidden && inForce.line_count > 0} decimals={2} seed={2} />{inForce.diff_pct !== null && <> <DiffBadge pct={inForce.diff_pct} /></>}</> : "Sem versão em vigor")}
                                        {kpi("Preço sem IVA (90 dias)", <Money value={article?.price_eur ?? null} hidden={hidden && (article?.qty_90d ?? 0) > 0} seed={3} />,
                                            <>Preço de tabela: <Money value={article?.list_price_eur ?? null} hidden={hidden} seed={4} /> com IVA</>)}
                                        {kpi("Food cost", inForce?.food_cost_pct !== null && inForce?.food_cost_pct !== undefined ? fmtPct(inForce.food_cost_pct) : "—")}
                                        {kpi("Margem", <Money value={inForce?.margin_eur ?? null} hidden={hidden && !!inForce && inForce.line_count > 0 && (article?.qty_90d ?? 0) > 0} seed={5} />)}
                                        {kpi("Quantidade vendida", fmtQty(article?.qty_90d ?? 0, 0), `De ${fmtDate(data.window.since)} a ${fmtDate(data.window.until)}`)}
                                        {kpi("Versão em vigor", inForce ? fmtDate(inForce.valid_from) : "—", inForce ? `${inForce.line_count} ${inForce.line_count === 1 ? "componente" : "componentes"}` : undefined)}
                                    </Row>
                                </>
                            )}
                        </PageCard>

                        {data && versions.length === 0 && (
                            <PageCard title="Ficha técnica" flush={false}>
                                <p className="text-muted mb-0">Este artigo não tem ficha técnica na PingWin (ou ainda não foi lida: a leitura corre de noite).</p>
                            </PageCard>
                        )}

                        {current && (
                            <PageCard
                                title="Versões da ficha"
                                status={<>Em vigor: a de data mais recente até hoje, não anulada, com a loja ativa</>}
                                actions={versions.length > 1 ? (
                                    <div className="xp-seg" role="tablist" aria-label="Versões">
                                        {versions.map((v) => (
                                            <button key={v.bom_id} type="button" role="tab" aria-selected={current.bom_id === v.bom_id}
                                                className={current.bom_id === v.bom_id ? "on" : ""} onClick={() => setTab(v.bom_id)}>
                                                {fmtDate(v.valid_from)}{versions.filter((x) => x.valid_from === v.valid_from).length > 1 && ` (versão ${Number(v.key) + 1})`}{v.in_force && <i className="ri-check-line ms-1" aria-label="em vigor" />}{v.deleted && " (anulada)"}
                                            </button>
                                        ))}
                                    </div>
                                ) : undefined}
                            >
                                <div className="px-3 pt-3 d-flex flex-wrap align-items-center gap-2">
                                    <span className="fw-semibold">Versão de {fmtDate(current.valid_from)}</span>
                                    {current.in_force
                                        ? <span className="badge bg-success-subtle text-success">em vigor</span>
                                        : current.deleted ? <span className="badge bg-secondary-subtle text-secondary">anulada</span>
                                            : <span className="badge bg-light text-body">não está em vigor</span>}
                                    <span className="text-muted fs-12 ms-2">Lojas:</span>
                                    <StoreBadges v={current} />
                                </div>
                                <LinesTable
                                    key={current.bom_id}
                                    lines={current.lines}
                                    hidden={hidden}
                                    tableKey={current.bom_id}
                                    total={{ cost: current.cost_eur, complete: current.cost_complete, pingwin: current.pingwin_cost_eur }}
                                />
                                {current.warnings.length > 0 && (
                                    <div className="border-top px-3 py-3" data-testid="recipe-warnings">
                                        <div className="fw-semibold fs-13 mb-2"><i className="ri-alert-line text-warning me-1" />Avisos ({current.warnings.length})</div>
                                        <ul className="mb-0 fs-13 ps-3">
                                            {current.warnings.map((w, i) => <li key={i}>{w.line ? <><strong>{w.line}</strong>: </> : null}{w.text}</li>)}
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
            </Container>
        </div>
    );
}
