import { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Alert, Badge, Button, ButtonGroup, Card, CardBody, Col, Container, Input, Label, Nav, NavItem, NavLink, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import InfoTip from "Components/Common/InfoTip";
import XSelect from "Components/Common/Select";
import { FinancialNote } from "Components/Common/HiddenMoney";
import AuditButton from "pages/Auditoria/AuditButton";
import { MenuParams, getMenuEngineering, recalculateMenuEngineering, updateMenuEngineeringSettings } from "helpers/laravel_helper";
import { MenuItem, MenuResponse, MenuSettings, PriceMode } from "common/models/menuEngineering.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import MatrixTab from "./menu/MatrixTab";
import TableTab from "./menu/TableTab";
import CoverageTab from "./menu/CoverageTab";
import FoodCostTab from "./menu/FoodCostTab";
import ItemDetailModal from "./menu/ItemDetailModal";
import { CLASS_META, CLASS_ORDER, CONFIDENCE_META, fmtPct } from "./menu/menuFormat";

/**
 * XPLENDOR — Restauração › Engenharia de menu (Fatia 3, só leitura). A matriz de Kasavana e Smith por
 * família, a tabela, a cobertura de custos (Pareto e lista de trabalho) e o food cost por compras. O
 * custo de cada prato vem das fichas e das compras de fornecedor (nunca do PCM do PingWin). Lê o
 * cálculo feito à noite (ou a pedido): "Calculado às HH:MM".
 */
type Tab = "matriz" | "tabela" | "cobertura" | "food-cost";
const TABS: { id: Tab; label: string }[] = [{ id: "matriz", label: "Matriz" }, { id: "tabela", label: "Tabela" }, { id: "cobertura", label: "Cobertura" }, { id: "food-cost", label: "Food cost" }];
const PERIODS = [{ value: 0, label: "Últimos 90 dias" }, { value: 30, label: "Últimos 30 dias" }, { value: 60, label: "Últimos 60 dias" }, { value: 180, label: "Últimos 180 dias" }];
const TOGGLES: { key: keyof MenuSettings; label: string }[] = [
    { key: "include_zero_price", label: "Artigos com preço 0 (modificadores)" },
    { key: "include_low_volume", label: "Artigos pouco vendidos" },
    { key: "include_retired", label: "Artigos sem vendas nos últimos 30 dias" },
    { key: "include_uber_eats", label: "Família Uber Eats" },
];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
export function periodRange(days: number, today = new Date()): { from?: string; to?: string } {
    if (!days) return {};
    const to = new Date(today);
    to.setDate(to.getDate() - 1);
    const from = new Date(to);
    from.setDate(from.getDate() - (days - 1));
    return { from: iso(from), to: iso(to) };
}

function Kpi({ title, value, sub, tip, testid }: { title: string; value: React.ReactNode; sub?: React.ReactNode; tip: string; testid?: string }) {
    return (
        <Card className="h-100 mb-0" data-testid={testid}>
            <CardBody className="py-3">
                <div className="text-muted fs-12 text-uppercase fw-medium">{title}<InfoTip label={title} text={tip} /></div>
                <div className="fs-4 fw-semibold mt-1">{value}</div>
                {sub && <div className="fs-12 text-muted">{sub}</div>}
            </CardBody>
        </Card>
    );
}

export default function MenuEngineeringPage() {
    document.title = "Engenharia de menu | Restauração | Xplendor";
    const companyId = useWorkingCompanyId();
    const [params, setParams] = useSearchParams();
    const tab = (TABS.find((t) => t.id === params.get("tab"))?.id ?? "matriz") as Tab;
    const [locationId, setLocationId] = useState<number>(0);
    const [mode, setMode] = useState<PriceMode>("media");
    const [days, setDays] = useState(0);
    const [data, setData] = useState<MenuResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [open, setOpen] = useState<MenuItem | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);

    const query: MenuParams = useMemo(() => ({ location_id: locationId || undefined, mode, ...periodRange(days) }), [locationId, mode, days]);
    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const res: any = await getMenuEngineering(companyId, query);
            setData(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a engenharia de menu.");
        } finally {
            setLoading(false);
        }
    }, [companyId, query]);
    useEffect(() => { setLoading(true); load(); }, [load, refreshKey]);
    const running = ["queued", "running"].includes(data?.running?.status ?? "");
    useEffect(() => {
        if (!data || (data.snapshot && !running)) return;
        const t = setTimeout(load, 4000);
        return () => clearTimeout(t);
    }, [data, running, load]);

    const recalc = async () => {
        if (!companyId) return;
        try {
            await recalculateMenuEngineering(companyId, query);
            toast.info("A recalcular. O ecrã atualiza sozinho.");
            load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível recalcular.");
        }
    };
    const saveToggle = async (key: keyof MenuSettings, value: boolean) => {
        if (!companyId) return;
        try {
            await updateMenuEngineeringSettings(companyId, { [key]: value });
            toast.info("Guardado. A recalcular.");
            load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        }
    };

    const snap = data?.snapshot ?? null;
    const s = snap?.summary;
    const hidden = data?.financial?.visible === false;
    const items = useMemo(() => data?.items ?? [], [data?.items]);
    const families = useMemo(() => Array.from(new Set(items.map((i) => i.family))).sort(), [items]);
    const calculatedAt = snap?.calculated_at ? new Date(snap.calculated_at) : null;

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Engenharia de menu"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="Que pratos vendem mais e deixam mais margem, por família (Kasavana e Smith), quanto da receita já tem custo conhecido e o food cost pelas compras. Só leitura: nada se escreve no PingWin."
                />
                <PageCard
                    title="Engenharia de menu"
                    loading={loading && !!data}
                    status={<>
                        {calculatedAt ? <>Calculado às {calculatedAt.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" })} de {calculatedAt.toLocaleDateString("pt-PT")}</> : "Ainda sem cálculo"}
                        {snap && <> · {new Date(`${snap.period.from}T00:00:00`).toLocaleDateString("pt-PT")} a {new Date(`${snap.period.to}T00:00:00`).toLocaleDateString("pt-PT")}</>}
                        {running && <> · <Spinner size="sm" /> a calcular</>}
                    </>}
                    actions={<>
                        <AuditButton companyId={companyId} area="restauracao" origin="menu" onFinished={() => setRefreshKey((k) => k + 1)} />
                        <Button size="sm" color="light" onClick={recalc} disabled={running}><i className="ri-refresh-line me-1" />Recalcular</Button>
                    </>}
                >
                    {hidden && <FinancialNote className="mb-2" />}
                    <Row className="g-2 align-items-end mb-3">
                        <Col xs={12} sm={4} md={3}>
                            <Label className="form-label mb-1">Período</Label>
                            <XSelect<number> value={days} ariaLabel="Período" options={PERIODS} onChange={setDays} />
                        </Col>
                        <Col xs={12} sm={4} md={3}>
                            <Label className="form-label mb-1">Loja</Label>
                            <XSelect<number> value={locationId} ariaLabel="Loja" options={[{ value: 0, label: "Yuko toda" }, ...(data?.locations ?? []).map((l) => ({ value: l.id, label: l.name }))]} onChange={setLocationId} />
                        </Col>
                        <Col xs={12} sm={4} md={6}>
                            <Label className="form-label mb-1 d-block">Custo dos ingredientes<InfoTip label="Custo dos ingredientes" text="Média de 90 dias: o preço médio das compras dos últimos 90 dias, pesado pela quantidade. Último custo: o preço da compra mais recente. Em ambos, o preço vem das faturas de fornecedor (sem IVA, com o desconto da linha), por unidade base e com os fatores confirmados na XPLENDOR." /></Label>
                            <ButtonGroup size="sm" data-testid="menu-mode">
                                <Button color={mode === "media" ? "primary" : "light"} onClick={() => setMode("media")} aria-pressed={mode === "media"}>Média de 90 dias</Button>
                                <Button color={mode === "ultimo" ? "primary" : "light"} onClick={() => setMode("ultimo")} aria-pressed={mode === "ultimo"}>Último custo</Button>
                            </ButtonGroup>
                        </Col>
                    </Row>

                    {!snap ? (
                        <Alert color="light" className="text-center py-4" data-testid="menu-pending"><Spinner size="sm" className="me-2" />A calcular a engenharia de menu para este período e loja. O ecrã atualiza sozinho.</Alert>
                    ) : (
                        <>
                            <Row className="g-2 mb-3">
                                <Col xs={12} md={6} xl={3}>
                                    <Kpi title="Cobertura" testid="menu-kpi-coverage" value={fmtPct(s!.coverage.with_cost_pct)}
                                        tip="A parte da receita sem IVA de artigos com custo conhecido. Confirmado: ficha e todos os ingredientes com preço e conversão. Parcial: falta o preço de algum ingrediente (o custo é um mínimo). Sem custo: sem ficha técnica."
                                        sub={<span className="d-flex flex-wrap gap-2">
                                            {(["confirmado", "parcial", "sem_custo"] as const).map((c) => (
                                                <span key={c}><i className="ri-checkbox-blank-fill me-1" style={{ color: CONFIDENCE_META[c].hex }} />{CONFIDENCE_META[c].label} {fmtPct(s!.coverage[`${c}_pct` as const])}</span>
                                            ))}
                                        </span>} />
                                </Col>
                                <Col xs={12} md={6} xl={3}>
                                    <Kpi title="Food cost por compras" testid="menu-kpi-purchases" value={fmtPct(s!.food_cost_purchases?.avg3_pct)}
                                        tip="Compras de comida e bebida (faturas de fornecedor, menos notas de crédito) a dividir pelas vendas sem IVA, nos 3 meses completos mais recentes, da empresa toda. Não precisa de fichas."
                                        sub={s!.food_cost_purchases ? `média de 3 meses até ${s!.food_cost_purchases.month}` : "sem meses completos"} />
                                </Col>
                                <Col xs={12} md={6} xl={3}>
                                    <Kpi title="Food cost teórico" testid="menu-kpi-theory" value={fmtPct(s!.food_cost_theoretical.pct)}
                                        tip="O custo das fichas × a quantidade vendida, a dividir pela receita, só dos artigos com custo. Vale para a parte da receita com custo (a cobertura ao lado)."
                                        sub={`só ${fmtPct(s!.food_cost_theoretical.coverage_pct)} da receita tem custo`} />
                                </Col>
                                <Col xs={12} md={6} xl={3}>
                                    <Kpi title="Artigos por classe" testid="menu-kpi-classes" value={CLASS_ORDER.reduce((n, c) => n + (s!.classes[c] ?? 0), 0)}
                                        tip="Os artigos na matriz com custo, por classe (Kasavana e Smith, dentro de cada família). Os sem custo ficam fora da matriz."
                                        sub={<span className="d-flex flex-wrap gap-1">{CLASS_ORDER.map((c) => <Badge key={c} color={CLASS_META[c].color} className="fs-11">{CLASS_META[c].label} {s!.classes[c] ?? 0}</Badge>)}</span>} />
                                </Col>
                            </Row>

                            {data?.can_manage && (
                                <details className="mb-3">
                                    <summary className="fs-13 text-muted">Exclusões da matriz ({Object.values(s!.excluded).reduce((a, b) => a + b, 0)} artigos de fora)</summary>
                                    <div className="d-flex flex-wrap gap-3 mt-2">
                                        {TOGGLES.map((t) => (
                                            <div key={t.key} className="form-check form-switch">
                                                <Input type="switch" className="form-check-input" id={`me-${t.key}`} checked={!!data?.settings[t.key]} onChange={(e) => saveToggle(t.key, e.target.checked)} />
                                                <Label className="form-check-label fs-13" for={`me-${t.key}`}>Incluir: {t.label}</Label>
                                            </div>
                                        ))}
                                    </div>
                                    {(data?.exclusions.length ?? 0) > 0 && <div className="fs-12 text-muted mt-1">Excluídos à mão: {data!.exclusions.map((x) => x.reason).join("; ")}.</div>}
                                </details>
                            )}

                            <Nav tabs className="nav-tabs-custom mb-3">
                                {TABS.map((t) => (
                                    <NavItem key={t.id}>
                                        <NavLink href="#" active={tab === t.id} onClick={(e) => { e.preventDefault(); setParams({ tab: t.id }, { replace: true }); }}>{t.label}</NavLink>
                                    </NavItem>
                                ))}
                            </Nav>
                            {tab === "matriz" && <MatrixTab items={items} families={snap.families} hidden={hidden} onOpen={setOpen} />}
                            {tab === "tabela" && companyId && <TableTab companyId={companyId} params={query} families={families} hidden={hidden} onOpen={setOpen} refreshKey={refreshKey + (snap.id ?? 0)} />}
                            {tab === "cobertura" && companyId && <CoverageTab companyId={companyId} snapshot={snap} hidden={hidden} onChanged={load} />}
                            {tab === "food-cost" && <FoodCostTab snapshot={snap} hidden={hidden} />}
                        </>
                    )}
                </PageCard>
            </Container>
            {open && companyId && (
                <ItemDetailModal companyId={companyId} item={open} params={query} hidden={hidden} canManage={!!data?.can_manage}
                    onClose={() => setOpen(null)} onChanged={load} />
            )}
        </div>
    );
}
