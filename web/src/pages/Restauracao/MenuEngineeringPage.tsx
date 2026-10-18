import { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Alert, Button, ButtonGroup, Card, CardBody, CardHeader, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
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
import MenuKpis from "./menu/MenuKpis";
import { fmtQty } from "./menu/menuFormat";

/**
 * XPLENDOR — Restauração › Engenharia de menu (Fatia 3, só leitura). Que pratos dão dinheiro e quais o
 * levam: a matriz de Kasavana e Smith por família, a tabela, a cobertura de custos e o food cost por
 * compras. Padrão das páginas da app: cabeçalho, barra de filtros e cartões sobre o fundo da página.
 */
type Tab = "matriz" | "tabela" | "cobertura" | "food-cost";
const TABS: { id: Tab; label: string; icon: string }[] = [
    { id: "matriz", label: "Matriz", icon: "ri-bubble-chart-line" }, { id: "tabela", label: "Tabela", icon: "ri-table-line" },
    { id: "cobertura", label: "Cobertura", icon: "ri-pie-chart-2-line" }, { id: "food-cost", label: "Food cost", icon: "ri-line-chart-line" },
];
const PERIODS = [{ value: 0, label: "Últimos 90 dias" }, { value: 30, label: "Últimos 30 dias" }, { value: 60, label: "Últimos 60 dias" }, { value: 180, label: "Últimos 180 dias" }];
const TOGGLES: { key: keyof MenuSettings; label: string }[] = [
    { key: "include_zero_price", label: "Pratos com preço 0 (modificadores)" },
    { key: "include_low_volume", label: "Pratos pouco vendidos" },
    { key: "include_retired", label: "Pratos sem vendas nos últimos 30 dias" },
    { key: "include_uber_eats", label: "Família Uber Eats" },
];
const EXCLUDED_LABEL: Record<string, string> = { preco_zero: "preço 0", pouco_vendido: "pouco vendidos", retirado: "sem vendas nos últimos 30 dias", uber_eats: "Uber Eats", manual: "excluídos à mão" };

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
export function periodRange(days: number, today = new Date()): { from?: string; to?: string } {
    if (!days) return {};
    const to = new Date(today);
    to.setDate(to.getDate() - 1);
    const from = new Date(to);
    from.setDate(from.getDate() - (days - 1));
    return { from: iso(from), to: iso(to) };
}
const dmy = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString("pt-PT");

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
    const [showExcluded, setShowExcluded] = useState(false);
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
    const hidden = data?.financial?.visible === false;
    const items = useMemo(() => data?.items ?? [], [data?.items]);
    const families = useMemo(() => Array.from(new Set(items.map((i) => i.family))).sort(), [items]);
    const excluded = useMemo(() => items.filter((i) => i.excluded), [items]);
    const calculatedAt = snap?.calculated_at ? new Date(snap.calculated_at) : null;
    const excludedCounts = snap ? Object.entries(snap.summary.excluded).filter(([, n]) => n > 0) : [];

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Engenharia de menu"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="Que pratos vendem mais e deixam mais margem, por família (Kasavana e Smith), quanto da receita já tem o custo conhecido e o food cost pelas compras. O custo vem das fichas técnicas e das faturas de fornecedor, nunca do PCM do PingWin. Só leitura: nada se escreve no PingWin."
                />

                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3" data-testid="menu-header">
                    <div>
                        <p className="fs-15 mb-0">Que pratos dão dinheiro e quais o levam</p>
                        <div className="fs-12 text-muted">
                            {calculatedAt ? <>Calculado às {calculatedAt.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" })} de {calculatedAt.toLocaleDateString("pt-PT")}</> : "Ainda sem cálculo"}
                            {snap && <> · vendas de {dmy(snap.period.from)} a {dmy(snap.period.to)}</>}
                            {running && <> · <Spinner size="sm" /> a calcular</>}
                        </div>
                    </div>
                    <div className="d-flex gap-2">
                        <AuditButton companyId={companyId} area="restauracao" origin="menu" onFinished={() => setRefreshKey((k) => k + 1)} />
                        <Button size="sm" color="primary" onClick={recalc} disabled={running}><i className="ri-refresh-line me-1" />Recalcular</Button>
                    </div>
                </div>

                <Card className="mb-3" data-testid="menu-filters">
                    <CardBody className="p-3">
                        <Row className="g-3 align-items-end">
                            <Col xs={12} sm={6} lg={3}>
                                <Label className="form-label mb-1" for="me-period">Período</Label>
                                <XSelect<number> id="me-period" value={days} ariaLabel="Período" options={PERIODS} onChange={setDays} />
                            </Col>
                            <Col xs={12} sm={6} lg={3}>
                                <Label className="form-label mb-1" for="me-location">Loja</Label>
                                <XSelect<number> id="me-location" value={locationId} ariaLabel="Loja" options={[{ value: 0, label: "Yuko toda" }, ...(data?.locations ?? []).map((l) => ({ value: l.id, label: l.name }))]} onChange={setLocationId} />
                            </Col>
                            <Col xs={12} lg={6}>
                                <div className="form-label mb-1 d-flex align-items-center gap-1">Custo dos ingredientes
                                    <InfoTip label="Custo dos ingredientes" text="Média de 90 dias: o preço médio das compras dos últimos 90 dias, pesado pela quantidade. Último custo: o preço da compra mais recente. Em ambos, o preço vem das faturas de fornecedor (sem IVA, com o desconto da linha), por unidade base e com os fatores confirmados na XPLENDOR." />
                                </div>
                                <ButtonGroup data-testid="menu-mode" className="d-flex" style={{ maxWidth: 360 }}>
                                    <Button color={mode === "media" ? "primary" : "light"} onClick={() => setMode("media")} aria-pressed={mode === "media"}>Média de 90 dias</Button>
                                    <Button color={mode === "ultimo" ? "primary" : "light"} onClick={() => setMode("ultimo")} aria-pressed={mode === "ultimo"}>Último custo</Button>
                                </ButtonGroup>
                            </Col>
                        </Row>
                    </CardBody>
                </Card>

                {hidden && <FinancialNote className="mb-3" />}

                {!snap ? (
                    <Card><CardBody className="text-center py-5" data-testid="menu-pending">
                        {loading && !data ? <Spinner size="sm" /> : <><Spinner size="sm" className="me-2" />A calcular a engenharia de menu para este período e loja. O ecrã atualiza sozinho.</>}
                    </CardBody></Card>
                ) : (
                    <>
                        <MenuKpis snapshot={snap} target={data?.targets?.food_cost_pct ?? 35} confirmedTarget={data?.targets?.confirmed_pct ?? 80} />

                        {excluded.length > 0 && (
                            <Alert color="info" className="d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 fs-13 mb-3" data-testid="menu-excluded-alert">
                                <span><i className="ri-information-line me-1" />
                                    {fmtQty(excluded.length)} pratos ficam fora da matriz: {excludedCounts.map(([k, n]) => `${EXCLUDED_LABEL[k] ?? k} (${n})`).join(", ")}.</span>
                                <Button size="sm" color="info" outline onClick={() => setShowExcluded(true)}>Ver</Button>
                            </Alert>
                        )}

                        <Card className="mb-3" data-testid="menu-tabs">
                            <CardHeader className="pb-0 border-bottom-0">
                                <Nav tabs className="nav-tabs-custom card-header-tabs border-bottom flex-nowrap overflow-auto text-nowrap">
                                    {TABS.map((t) => (
                                        <NavItem key={t.id}>
                                            <NavLink href="#" active={tab === t.id} onClick={(e) => { e.preventDefault(); setParams({ tab: t.id }, { replace: true }); }}>
                                                <i className={`${t.icon} me-1 align-bottom`} />{t.label}
                                            </NavLink>
                                        </NavItem>
                                    ))}
                                </Nav>
                            </CardHeader>
                            <CardBody className="p-3 p-md-4">
                                {tab === "matriz" && <MatrixTab items={items} snapshot={snap} families={snap.families} hidden={hidden} plays={data?.labels?.plays ?? {}} onOpen={setOpen} />}
                                {tab === "tabela" && companyId && <TableTab companyId={companyId} params={query} families={families} hidden={hidden} onOpen={setOpen} refreshKey={refreshKey + (snap.id ?? 0)} />}
                                {tab === "cobertura" && companyId && <CoverageTab companyId={companyId} snapshot={snap} hidden={hidden} onChanged={load} />}
                                {tab === "food-cost" && <FoodCostTab snapshot={snap} hidden={hidden} target={data?.targets?.food_cost_pct ?? 35} />}
                            </CardBody>
                        </Card>
                    </>
                )}
            </Container>

            {open && companyId && (
                <ItemDetailModal companyId={companyId} item={open} params={query} hidden={hidden} canManage={!!data?.can_manage}
                    onClose={() => setOpen(null)} onChanged={load} />
            )}
            <Modal isOpen={showExcluded} toggle={() => setShowExcluded(false)} size="lg" scrollable data-testid="menu-excluded-modal">
                <ModalHeader toggle={() => setShowExcluded(false)}>Pratos fora da matriz</ModalHeader>
                <ModalBody>
                    {data?.can_manage && (
                        <div className="mb-3">
                            <div className="fs-13 fw-medium mb-2">Incluir na matriz</div>
                            <div className="d-flex flex-wrap gap-3">
                                {TOGGLES.map((t) => (
                                    <div key={t.key} className="form-check form-switch">
                                        <Input type="switch" className="form-check-input" id={`me-${t.key}`} checked={!!data?.settings[t.key]} onChange={(e) => saveToggle(t.key, e.target.checked)} />
                                        <Label className="form-check-label fs-13" for={`me-${t.key}`}>{t.label}</Label>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                    <Table size="sm" className="fs-13 mb-0 align-middle">
                        <thead><tr><th>Prato</th><th>Família</th><th className="text-end">Vendidos</th><th>Motivo</th></tr></thead>
                        <tbody>
                            {excluded.map((i) => (
                                <tr key={i.product_id} role="button" onClick={() => { setShowExcluded(false); setOpen(i); }}>
                                    <td>{i.description}</td><td className="text-muted">{i.family}</td><td className="text-end">{fmtQty(i.qty)}</td><td>{i.excluded_reason}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </ModalBody>
                <ModalFooter><Button color="light" onClick={() => setShowExcluded(false)}>Fechar</Button></ModalFooter>
            </Modal>
        </div>
    );
}
