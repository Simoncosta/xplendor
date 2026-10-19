import { useEffect, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { Alert, Button, Card, CardBody, Container } from "reactstrap";
import Skeleton from "Components/Common/Skeleton";
import { getFinanceMonth } from "helpers/laravel_helper";
import { FinanceMonth } from "common/models/financeMonth.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import MonthTop from "./mes/MonthTop";
import ResultadoTab from "./mes/ResultadoTab";
import { lastMonth } from "./FinancasMesPage";
import { ucfirst } from "./mes/format";

/**
 * XPLENDOR — Finanças › Mês › Fecho do mês: a página para imprimir (ou guardar em PDF pelo navegador)
 * com o topo e o separador Resultado, para a responsável administrativa enviar à dona.
 */
const PRINT_CSS = `
@media print {
    @page { size: A4; margin: 12mm; }
    #page-topbar, .app-menu, .vertical-overlay, .fm-no-print, .Toastify, .xplndor-cookie-banner, a[aria-label="Suporte"], .xp-infotip { display: none !important; }
    html, body, #layout-wrapper, .main-content, .page-content { background: #fff !important; }
    .main-content { margin-left: 0 !important; }
    .page-content { padding: 0 !important; }
    .fm-print-area .card { border: 1px solid #ddd !important; box-shadow: none !important; }
    .fm-print-area .table-responsive { overflow: visible !important; }
    .fm-print-area table { font-size: 10px; }
    .fm-print-area tr { break-inside: avoid; }
    .fm-print-area .col-xl-3 { flex: 0 0 25%; max-width: 25%; }
}
`;

export default function FechoMesPage() {
    const companyId = useWorkingCompanyId();
    const navigate = useNavigate();
    const [params] = useSearchParams();
    const month = params.get("mes") ?? lastMonth();
    const store = Number(params.get("loja") ?? 0);
    const [data, setData] = useState<FinanceMonth | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!companyId) return;
        getFinanceMonth(companyId, { month, store: store || null })
            .then((r: any) => setData(r?.data ?? null))
            .catch((e: any) => setError(e?.message ?? "Não foi possível carregar o mês."));
    }, [companyId, month, store]);

    const title = data ? `Fecho de ${data.month_label}` : "Fecho do mês";
    document.title = `${title} | Finanças | Xplendor`;
    const storeName = data && store ? data.stores.find((s) => s.id === store)?.name ?? null : null;

    return (
        <div className="page-content">
            <style>{PRINT_CSS}</style>
            <Container fluid className="fm-print-area" data-testid="finance-print">
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <h4 className="mb-0">{ucfirst(title)}</h4>
                        <div className="fs-12 text-muted">Gerado a {new Date().toLocaleDateString("pt-PT")} · vendas: {storeName ?? "todos os restaurantes"}</div>
                    </div>
                    <div className="d-flex gap-2 fm-no-print">
                        <Button color="light" onClick={() => navigate(`/financas/mes?mes=${month}${store ? `&loja=${store}` : ""}`)}>Voltar</Button>
                        <Button color="primary" onClick={() => window.print()} disabled={!data} data-testid="finance-print-button"><i className="ri-printer-line me-1" />Imprimir ou guardar em PDF</Button>
                    </div>
                </div>
                {error && <Alert color="danger">{error}</Alert>}
                {!data && !error && <Card><CardBody><Skeleton variant="lines" lines={8} /></CardBody></Card>}
                {data && (
                    <>
                        <MonthTop data={data} storeName={storeName} />
                        <Card><CardBody><h5 className="mb-3">Resultado</h5><ResultadoTab data={data} printable /></CardBody></Card>
                    </>
                )}
            </Container>
        </div>
    );
}
