import { useCallback, useEffect, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Alert, Button, Card, CardBody, Col, Container, Label, Nav, NavItem, NavLink, Row } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import XSelect from "Components/Common/Select";
import Skeleton from "Components/Common/Skeleton";
import { getFinanceMonth } from "helpers/laravel_helper";
import { FinanceMonth } from "common/models/financeMonth.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import SupplierDocumentLinesModal from "pages/Restauracao/faturas/SupplierDocumentLinesModal";
import ResumoTab, { DetailKind } from "./mes/ResumoTab";
import TrabalhoTab, { WORK_SECTIONS, WorkSection } from "./mes/TrabalhoTab";
import DetailPanel from "./mes/DetailPanel";

/**
 * XPLENDOR — Finanças › Mês, por camadas. Resumo (por omissão, para todos): o estado do mês, os quatro
 * números, para onde foi o dinheiro e os últimos 6 meses. O detalhe (um grupo, o resultado por família,
 * o dinheiro, os documentos) abre num painel lateral. Trabalho do mês (só com Finanças › Editar): o que
 * falta fazer e o sítio de cada tarefa. Nada se escreve no PingWin, exceto o "Fechar no PingWin" dos
 * documentos em Aberto.
 */
type Tab = "resumo" | "trabalho";
const TABS: { id: Tab; label: string; icon: string }[] = [
    { id: "resumo", label: "Resumo", icon: "ri-pie-chart-2-line" },
    { id: "trabalho", label: "Trabalho do mês", icon: "ri-task-line" },
];

/** O mês anterior ao de hoje (o último mês completo), "AAAA-MM". */
export function lastMonth(today = new Date()): string {
    const d = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

export default function FinancasMesPage() {
    document.title = "Mês | Finanças | Xplendor";
    const companyId = useWorkingCompanyId();
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const rawTab = params.get("tab");
    const month = params.get("mes") ?? lastMonth();
    const store = Number(params.get("loja") ?? 0);
    const [data, setData] = useState<FinanceMonth | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [openDoc, setOpenDoc] = useState<string | null>(null);

    const set = (patch: Record<string, string | null>) => {
        const next = new URLSearchParams(params);
        Object.entries(patch).forEach(([k, v]) => (v === null || v === "" || v === "0" ? next.delete(k) : next.set(k, v)));
        setParams(next, { replace: true });
    };

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const r: any = await getFinanceMonth(companyId, { month, store: store || null });
            setData(r?.data ?? null);
            setError(null);
        } catch (e: any) {
            setError(e?.message ?? "Não foi possível carregar o mês.");
        } finally {
            setLoading(false);
        }
    }, [companyId, month, store]);

    useEffect(() => { load(); }, [load]);

    const storeName = data && store ? data.stores.find((s) => s.id === store)?.name ?? null : null;
    const canEdit = !!data?.can.classify;
    // Os separadores antigos (Resultado, Tesouraria, Documentos) continuam a abrir o sítio certo.
    const legacy: Record<string, { sec: WorkSection; panel: DetailKind } | undefined> = {
        tesouraria: { sec: "pagamentos", panel: "dinheiro" }, documentos: { sec: "documentos", panel: "documentos" },
    };
    const old = rawTab ? legacy[rawTab] : undefined;
    const tab: Tab = canEdit && (rawTab === "trabalho" || old) ? "trabalho" : "resumo";
    const sec = (WORK_SECTIONS.find((w) => w.id === params.get("sec"))?.id ?? old?.sec ?? "documentos") as WorkSection;
    const panel = (params.get("painel") as DetailKind | null) ?? (!canEdit && old ? old.panel : null);
    const goWork = (s: WorkSection, f?: { group?: string; state?: string }) =>
        set({ tab: "trabalho", sec: s === "documentos" ? null : s, grupo: f?.group ?? null, estado: f?.state ?? null, painel: null });
    const closeMonth = () => navigate(`/financas/mes/fecho?mes=${month}${store ? `&loja=${store}` : ""}`);

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Mês"
                    breadcrumbs={[{ label: "Finanças" }]}
                    info="O mês num só ecrã: o Resumo com as vendas, os custos, o resultado e o dinheiro, e o detalhe ao tocar em cada bloco; com Finanças › Editar, o Trabalho do mês com o que falta fazer. Lê os documentos de fornecedor, a conta corrente e as vendas do PingWin."
                />
                <Card className="mb-3" data-testid="finance-filters">
                    <CardBody className="p-3">
                        <Row className="g-3 align-items-end">
                            <Col xs={12} sm={6} lg={3}>
                                <Label className="form-label mb-1" for="fm-month">Mês</Label>
                                <input id="fm-month" type="month" className="form-control" value={month} max={lastMonth(new Date(new Date().getFullYear(), new Date().getMonth() + 2, 1))}
                                    onChange={(e) => e.target.value && set({ mes: e.target.value })} />
                            </Col>
                            <Col xs={12} sm={6} lg={3}>
                                <Label className="form-label mb-1" for="fm-store">Lojas (vendas)</Label>
                                <XSelect<number> id="fm-store" ariaLabel="Lojas (vendas)" value={store}
                                    options={[{ value: 0, label: "Todos os restaurantes" }, ...(data?.stores ?? []).map((s) => ({ value: s.id, label: s.name }))]}
                                    onChange={(v) => set({ loja: String(v) })} />
                            </Col>
                            <Col xs={12} lg={6} className="text-lg-end">
                                <Button color="light" onClick={closeMonth} disabled={!data} data-testid="finance-close-month">
                                    <i className="ri-file-pdf-2-line me-1" />Gerar fecho do mês
                                </Button>
                            </Col>
                        </Row>
                    </CardBody>
                </Card>

                {error && <Alert color="danger">{error}</Alert>}
                {!data && loading && <Card><CardBody><Skeleton variant="lines" lines={8} /></CardBody></Card>}
                {data && (
                    <div style={{ opacity: loading ? 0.6 : 1 }}>
                        {canEdit && (
                            <Nav tabs className="nav-tabs-custom border-bottom mb-3 flex-column flex-sm-row" data-testid="finance-tabs">
                                {TABS.map((t) => (
                                    <NavItem key={t.id}>
                                        <NavLink href="#" active={tab === t.id} onClick={(e) => { e.preventDefault(); set({ tab: t.id === "resumo" ? null : t.id, sec: null, grupo: null, estado: null, painel: null }); }}
                                            data-testid={`finance-tab-${t.id}`}>
                                            <i className={`${t.icon} me-1 align-bottom`} />{t.label}
                                            {t.id === "trabalho" && data.documents.unclassified.length > 0 && <span className="badge bg-warning-subtle text-warning ms-1">{data.documents.unclassified.length} por classificar</span>}
                                            {t.id === "trabalho" && data.documents.open.count > 0 && <span className="badge bg-warning-subtle text-warning ms-1">{data.documents.open.count} em aberto</span>}
                                        </NavLink>
                                    </NavItem>
                                ))}
                            </Nav>
                        )}
                        {tab === "resumo" && (
                            <ResumoTab data={data} storeName={storeName} canEdit={canEdit} onDetail={(k) => set({ painel: k })}
                                onSeeMissing={() => goWork("habituais")} />
                        )}
                        {tab === "trabalho" && companyId && (
                            <Card className="mb-3"><CardBody className="p-3 p-md-4">
                                <TrabalhoTab data={data} companyId={companyId} sec={sec} group={params.get("grupo") ?? ""} state={params.get("estado") ?? ""}
                                    onGo={goWork} onOpen={setOpenDoc} onChanged={load} onCloseMonth={closeMonth} />
                            </CardBody></Card>
                        )}
                        <DetailPanel kind={panel} data={data} companyId={companyId} onClose={() => set({ painel: null, ...(old ? { tab: null } : {}) })}
                            onDetail={(k) => set({ painel: k })} onOpen={setOpenDoc} onChanged={load} />
                    </div>
                )}
            </Container>
            {openDoc && companyId && <SupplierDocumentLinesModal companyId={companyId} docheaderId={openDoc} onClose={() => setOpenDoc(null)} />}
        </div>
    );
}
