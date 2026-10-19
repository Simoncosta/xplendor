import { useCallback, useEffect, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Alert, Button, Card, CardBody, CardHeader, Col, Container, Label, Nav, NavItem, NavLink, Row } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import XSelect from "Components/Common/Select";
import Skeleton from "Components/Common/Skeleton";
import { getFinanceMonth } from "helpers/laravel_helper";
import { FinanceMonth } from "common/models/financeMonth.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import SupplierDocumentLinesModal from "pages/Restauracao/faturas/SupplierDocumentLinesModal";
import MonthTop from "./mes/MonthTop";
import ResultadoTab from "./mes/ResultadoTab";
import TesourariaTab from "./mes/TesourariaTab";
import DocumentosTab from "./mes/DocumentosTab";

/**
 * XPLENDOR — Finanças › Mês: resultado e tesouraria num só ecrã (só leitura; o "Fechar no PingWin" dos
 * documentos em Aberto é o da lista de documentos). Para a dona (o resumo, se o mês deu lucro e o que
 * sai) e para a responsável administrativa (preparar, classificar e enviar o fecho do mês). Padrão da
 * Engenharia de menu: cartões sobre o fundo da página, um (i) em cada número a dizer de onde vem; no
 * telemóvel, o topo e os separadores em lista.
 */
type Tab = "resultado" | "tesouraria" | "documentos";
const TABS: { id: Tab; label: string; icon: string }[] = [
    { id: "resultado", label: "Resultado", icon: "ri-funds-line" },
    { id: "tesouraria", label: "Tesouraria", icon: "ri-bank-line" },
    { id: "documentos", label: "Documentos", icon: "ri-file-list-3-line" },
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
    const tab = (TABS.find((t) => t.id === params.get("tab"))?.id ?? "resultado") as Tab;
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

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader
                    title="Mês"
                    breadcrumbs={[{ label: "Finanças" }]}
                    info="O resultado e o dinheiro do mês num só ecrã: vendas, gastos por família, resultado, o que saiu e o que falta pagar. Lê os documentos de fornecedor, a conta corrente e as vendas do PingWin; nada se escreve no PingWin."
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
                                <Button color="primary" onClick={() => navigate(`/financas/mes/fecho?mes=${month}${store ? `&loja=${store}` : ""}`)} disabled={!data} data-testid="finance-close-month">
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
                        <MonthTop data={data} storeName={storeName} companyId={companyId} canEdit={data.can.classify} onChanged={load} />
                        <Card className="mb-3" data-testid="finance-tabs">
                            <CardHeader className="pb-0 border-bottom-0">
                                {/* No telemóvel os separadores ficam em lista. */}
                                <Nav tabs className="nav-tabs-custom card-header-tabs border-bottom flex-column flex-sm-row">
                                    {TABS.map((t) => (
                                        <NavItem key={t.id}>
                                            <NavLink href="#" active={tab === t.id} onClick={(e) => { e.preventDefault(); set({ tab: t.id === "resultado" ? null : t.id }); }}>
                                                <i className={`${t.icon} me-1 align-bottom`} />{t.label}
                                                {t.id === "documentos" && data.documents.unclassified.length > 0 && <span className="badge bg-danger-subtle text-danger ms-1">{data.documents.unclassified.length} por classificar</span>}
                                                {t.id === "documentos" && data.documents.open.count > 0 && <span className="badge bg-warning-subtle text-warning ms-1">{data.documents.open.count} em aberto</span>}
                                            </NavLink>
                                        </NavItem>
                                    ))}
                                </Nav>
                            </CardHeader>
                            <CardBody className="p-3 p-md-4">
                                {tab === "resultado" && <ResultadoTab data={data} />}
                                {tab === "tesouraria" && companyId && <TesourariaTab companyId={companyId} data={data} onOpen={setOpenDoc} onChanged={load} />}
                                {tab === "documentos" && companyId && <DocumentosTab companyId={companyId} data={data} onOpen={setOpenDoc} onChanged={load} />}
                            </CardBody>
                        </Card>
                    </div>
                )}
            </Container>
            {openDoc && companyId && <SupplierDocumentLinesModal companyId={companyId} docheaderId={openDoc} onClose={() => setOpenDoc(null)} />}
        </div>
    );
}
